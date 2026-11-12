<?php

namespace App\Services;

use App\Guardrails\Guard;
use App\Guardrails\StackSet;
use App\Models\Run;
use App\Models\Scorecard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Measures a run's facts from its transcript and its log, in plain code with no model call (ADR 0008). */
class RunScorer
{
    /**
     * A shell command that runs tests: a test tool at the start of the command or of one step in a chain, after any
     * env vars and a launcher such as `npx`. So `cat phpunit.xml` and `grep jest package.json` don't count.
     */
    private const TEST_COMMAND = '/'.Guard::COMMAND_START
        .'(?:(?:(?:npx|bunx|php|python3?\s+-m|uv\s+run)\s+)?(?:\S*\/)?(?:pest|phpunit|vitest|jest|pytest)(?![\w.\/-])'
        .'|php\s+artisan\s+test\b'
        .'|(?:npm|pnpm|yarn|bun|composer)\s+(?:run\s+)?test\b'
        .'|cargo\s+(?:\+\S+\s+)?(?:test|nextest)\b)/';

    /** A test run narrowed by a filter flag or a test file. */
    private const FILTERED = '/\s(--filter|--testNamePattern|-t|-k)[\s=]|\s\S*(Test\.php|\.(test|spec)\.[cm]?[jt]sx?|_test\.py|test_\w+\.py)\b/';

    public function score(Run $run): Scorecard
    {
        ['tools' => $tools, 'failed' => $failed, 'problem' => $transcriptProblem] = $this->transcript($run->rawLogPath());
        ['text' => $log, 'problem' => $logProblem] = $this->read($run->logPath());

        $testRuns = $this->testRuns($tools);

        return $run->scorecard()->create([
            'log_problem' => implode(' ', array_filter([$transcriptProblem, $logProblem])) ?: null,
            'tool_errors' => $this->toolErrors($tools, $failed),
            'repeated_reads' => $this->repeatedReads($tools),
            'full_test_runs' => $testRuns['full'],
            'filtered_test_runs' => $testRuns['filtered'],
            // Lines StopHook and ProcessRun write. Only entries that start a log line count, not text quoted inside one.
            'stop_hook_blocks' => preg_match_all('/^\[[\d:]+\] Blocked the stop \(/m', $log),
            // Stop-hook blocks that name the verify script. The gate's own failure line doesn't say which check failed.
            'verify_failures' => preg_match_all('/^\[[\d:]+\] Blocked the stop \(.*Verify script.* failed\.$/m', $log),
            'scope_vetoes' => preg_match_all('/^\[[\d:]+\] Scope judge vetoed the change/m', $log),
            'test_methods_added' => $this->testMethodsAdded($tools, (string) $run->worktree_path),
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

    /**
     * The agent's tool calls and the ids of those that failed, skipping lines that aren't JSON objects, and why the
     * transcript can't be scored when it is missing or blank. Read a line at a time and kept to those blocks, so a long
     * run's tool output never sits in memory.
     *
     * @return array{tools:list<array<string,mixed>>,failed:list<string>,problem:?string}
     */
    private function transcript(string $path): array
    {
        $name = basename($path);
        $tools = $failed = [];
        $blank = true;

        if (! File::exists($path)) {
            return ['tools' => [], 'failed' => [], 'problem' => "{$name} is missing."];
        }

        foreach (File::lines($path) as $line) {
            $blank = $blank && trim($line) === '';
            $event = json_decode($line, true);
            $content = is_array($event) ? ($event['message']['content'] ?? null) : null;

            foreach (is_array($content) ? $content : [] as $block) {
                $type = [$event['type'] ?? null, $block['type'] ?? null];

                if ($type === ['assistant', 'tool_use']) {
                    $tools[] = $block;
                } elseif ($type === ['user', 'tool_result'] && ($block['is_error'] ?? false)) {
                    $failed[] = (string) ($block['tool_use_id'] ?? '');
                }
            }
        }

        return ['tools' => $tools, 'failed' => $failed, 'problem' => $blank ? "{$name} is empty." : null];
    }

    /** @return array<string,int> Tool name => failed calls, most first. */
    private function toolErrors(array $tools, array $failed): array
    {
        $names = array_column($tools, 'name', 'id');
        $errors = array_count_values(array_map(fn (string $id) => $names[$id] ?? 'unknown', $failed));
        arsort($errors);

        return $errors;
    }

    /**
     * Bash calls that ran tests: the whole suite, or narrowed by a filter or a file. Each line and each step of a chain is
     * checked on its own, so a test file named by `php -l` in another step doesn't make the run look filtered.
     *
     * @return array{full:int,filtered:int}
     */
    private function testRuns(array $tools): array
    {
        $runs = ['full' => 0, 'filtered' => 0];

        foreach ($tools as $tool) {
            $command = $tool['input']['command'] ?? null;

            if (($tool['name'] ?? null) !== 'Bash' || ! is_string($command)) {
                continue;
            }

            $steps = preg_grep(self::TEST_COMMAND, preg_split('/[;&|\n]/', $command));

            if ($steps) {
                $runs[preg_match(self::FILTERED, implode(' ', $steps)) ? 'filtered' : 'full']++;
            }
        }

        return $runs;
    }

    /**
     * Test markers each edit added to a test file, by the stacks' own tables as the test-integrity check counts them.
     * Rust unit tests sit in source files, so any `.rs` file counts. An edit that removes tests adds none.
     * A Write counts against the tests the transcript already put in that file. A file that existed before the run
     * and is rewritten whole counts all its tests, since the transcript doesn't hold what it had.
     */
    private function testMethodsAdded(array $tools, string $worktree): int
    {
        $stacks = StackSet::all();
        $testFiles = $stacks->rules('testFiles');
        $markers = $stacks->rules('testMarkers');
        $known = [];   // path => tests the transcript put there so far
        $added = 0;

        foreach ($tools as $tool) {
            $input = $tool['input'] ?? [];
            $path = (string) ($input['file_path'] ?? '');
            // Relative to the worktree, so a `tests` folder above it doesn't make every file a test file
            $path = $worktree === '' ? $path : Str::after($path, rtrim($worktree, '/').'/');

            if (! str_ends_with($path, '.rs') && ! Guard::matchesAny($testFiles, $path, anywhere: true)) {
                continue;
            }

            $changes = match ($tool['name'] ?? null) {
                'Write' => [$this->countMarkers((string) ($input['content'] ?? ''), $markers) - ($known[$path] ?? 0)],
                'Edit' => [$this->markersAdded($input, $markers)],
                'MultiEdit' => array_map(fn ($edit) => $this->markersAdded((array) $edit, $markers), $input['edits'] ?? []),
                default => [],
            };

            foreach ($changes as $change) {
                // Tests removed from what the file had before the run take nothing off a later Write
                $known[$path] = max(0, ($known[$path] ?? 0) + $change);
                $added += max(0, $change);
            }
        }

        return $added;
    }

    /** Test markers one edit added, less those it removed. */
    private function markersAdded(array $edit, array $markers): int
    {
        return $this->countMarkers((string) ($edit['new_string'] ?? ''), $markers) - $this->countMarkers((string) ($edit['old_string'] ?? ''), $markers);
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

    /**
     * Path => reads of its most-read range, for files with a range read 3 or more times, most first.
     * Reading a big file in pages reads each range once, so it doesn't count.
     *
     * @return array<string,int>
     */
    private function repeatedReads(array $tools): array
    {
        $ranges = [];

        foreach ($tools as $tool) {
            $input = $tool['input'] ?? [];

            if (($tool['name'] ?? null) === 'Read' && is_string($input['file_path'] ?? null)) {
                $range = ($input['offset'] ?? '').':'.($input['limit'] ?? '');
                $ranges[$input['file_path']][$range] = ($ranges[$input['file_path']][$range] ?? 0) + 1;
            }
        }

        $reads = array_filter(array_map(fn (array $counts) => max($counts), $ranges), fn (int $count) => $count >= 3);
        arsort($reads);

        return $reads;
    }
}
