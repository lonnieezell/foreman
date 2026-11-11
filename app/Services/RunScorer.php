<?php

namespace App\Services;

use App\Guardrails\Guard;
use App\Guardrails\Stacks\Php;
use App\Guardrails\Stacks\Python;
use App\Guardrails\Stacks\Rust;
use App\Guardrails\Stacks\TypeScript;
use App\Guardrails\StackSet;
use App\Models\Run;
use App\Models\Scorecard;
use Illuminate\Support\Facades\File;

/** Measures a run's facts from its transcript and its log, in plain code with no model call (ADR 0008). */
class RunScorer
{
    /** A shell command that runs tests. */
    private const TEST_COMMAND = '/\b(pest|phpunit|artisan test|vitest|jest|pytest)\b'
        .'|\b(npm|pnpm|yarn|bun|composer)\s+(run\s+)?test\b'
        .'|\bcargo\s+(\+\S+\s+)?(test|nextest)\b/';

    /** A test run narrowed by a filter flag or a test file. */
    private const FILTERED = '/\s(--filter|--testNamePattern|-t|-k)[\s=]|\s\S*(Test\.php|\.(test|spec)\.[cm]?[jt]sx?|_test\.py|test_\w+\.py)\b/';

    public function score(Run $run): Scorecard
    {
        ['text' => $transcript, 'problem' => $transcriptProblem] = $this->read($run->rawLogPath());
        ['text' => $log, 'problem' => $logProblem] = $this->read($run->logPath());

        $events = $this->events($transcript);
        $tools = $this->blocks($events, 'assistant', 'tool_use');
        $testRuns = $this->testRuns($tools);

        return $run->scorecard()->create([
            'log_problem' => implode(' ', array_filter([$transcriptProblem, $logProblem])) ?: null,
            'tool_errors' => $this->toolErrors($events, $tools),
            'repeated_reads' => $this->repeatedReads($tools),
            'full_test_runs' => $testRuns['full'],
            'filtered_test_runs' => $testRuns['filtered'],
            // Lines StopHook and ProcessRun write. Only entries that start a log line count, not text quoted inside one.
            'stop_hook_blocks' => preg_match_all('/^\[[\d:]+\] Blocked the stop \(/m', $log),
            // The gate's failure doesn't name its check, so it counts too: the verify script runs in the gate
            'verify_failures' => preg_match_all('/^\[[\d:]+\] (Blocked the stop \(.*Verify script.* failed\.$|Foreman\'s gate failed\.)/m', $log),
            'scope_vetoes' => preg_match_all('/^\[[\d:]+\] Scope judge vetoed the change/m', $log),
            'test_methods_added' => $this->testMethodsAdded($tools),
        ]);
    }

    /** @return array{text:string,problem:?string} The file's text, and why it can't be scored when it is missing or blank. */
    private function read(string $path): array
    {
        $name = basename($path);

        if (! File::exists($path)) {
            return ['text' => '', 'problem' => "{$name} is missing."];
        }

        $text = File::get($path);

        return ['text' => $text, 'problem' => trim($text) === '' ? "{$name} is empty." : null];
    }

    /** @return list<array<string,mixed>> The transcript's events, skipping lines that aren't JSON objects. */
    private function events(string $transcript): array
    {
        return array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), explode("\n", $transcript)), 'is_array'));
    }

    /**
     * Content blocks of one type from the events of one type.
     *
     * @return list<array<string,mixed>>
     */
    private function blocks(array $events, string $eventType, string $blockType): array
    {
        $blocks = [];

        foreach ($events as $event) {
            if (($event['type'] ?? null) !== $eventType || ! is_array($event['message']['content'] ?? null)) {
                continue;
            }

            foreach ($event['message']['content'] as $block) {
                if (($block['type'] ?? null) === $blockType) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /** @return array<string,int> Tool name => failed calls, most first. */
    private function toolErrors(array $events, array $tools): array
    {
        $names = array_column($tools, 'name', 'id');
        $errors = [];

        foreach ($this->blocks($events, 'user', 'tool_result') as $result) {
            if ($result['is_error'] ?? false) {
                $name = $names[$result['tool_use_id'] ?? ''] ?? 'unknown';
                $errors[$name] = ($errors[$name] ?? 0) + 1;
            }
        }

        arsort($errors);

        return $errors;
    }

    /** @return array{full:int,filtered:int} Bash calls that ran tests: the whole suite, or narrowed by a filter or a file. */
    private function testRuns(array $tools): array
    {
        $runs = ['full' => 0, 'filtered' => 0];

        foreach ($tools as $tool) {
            $command = $tool['input']['command'] ?? null;

            if (($tool['name'] ?? null) === 'Bash' && is_string($command) && preg_match(self::TEST_COMMAND, $command)) {
                $runs[preg_match(self::FILTERED, $command) ? 'filtered' : 'full']++;
            }
        }

        return $runs;
    }

    /**
     * Test markers each edit added to a test file, by the stacks' own tables as the test-integrity check counts them.
     * Rust unit tests sit in source files, so any `.rs` file counts. An edit that removes tests adds none.
     */
    private function testMethodsAdded(array $tools): int
    {
        $stacks = new StackSet(array_map(fn (string $stack) => new $stack('', ['']), [Php::class, TypeScript::class, Rust::class, Python::class]));
        $testFiles = $stacks->rules('testFiles');
        $markers = $stacks->rules('testMarkers');
        $added = 0;

        foreach ($tools as $tool) {
            $input = $tool['input'] ?? [];
            $path = (string) ($input['file_path'] ?? '');

            if (! str_ends_with($path, '.rs') && ! Guard::matchesAny($testFiles, $path, anywhere: true)) {
                continue;
            }

            $edits = match ($tool['name'] ?? null) {
                'Write' => [['old_string' => '', 'new_string' => $input['content'] ?? '']],
                'Edit' => [$input],
                'MultiEdit' => $input['edits'] ?? [],
                default => [],
            };

            foreach ($edits as $edit) {
                $added += max(0, $this->countMarkers((string) ($edit['new_string'] ?? ''), $markers) - $this->countMarkers((string) ($edit['old_string'] ?? ''), $markers));
            }
        }

        return $added;
    }

    /** Same rule as the test-integrity script: a marker that starts with a letter can't follow one, so `->setUp(` isn't `test(`. */
    private function countMarkers(string $text, array $markers): int
    {
        $count = 0;

        foreach ($markers as $marker) {
            $start = preg_match('/^\w/', $marker) ? '(?<!\w)' : '';
            $count += (int) preg_match_all('/'.$start.preg_quote($marker, '/').'/', $text);
        }

        return $count;
    }

    /** @return array<string,int> Path => reads, for files read 3 or more times, most first. */
    private function repeatedReads(array $tools): array
    {
        $paths = [];

        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === 'Read' && is_string($tool['input']['file_path'] ?? null)) {
                $paths[] = $tool['input']['file_path'];
            }
        }

        $reads = array_filter(array_count_values($paths), fn (int $count) => $count >= 3);
        arsort($reads);

        return $reads;
    }
}
