<?php

namespace App\Guardrails;

/**
 * How risky a run's change is, worked out from its diff. The tier decides how deep the review pass goes.
 *
 * - Tier 3: touches a tier-3 path of a detected stack, or adds a line matching its risky-code patterns.
 * - Tier 1: only docs or tests, and under 200 changed lines.
 * - Tier 2: everything else.
 */
class RiskTier
{
    public const TIER1_MAX_LINES = 200;

    /** Docs in any repository. Matched anywhere in the path, like the stacks' rules. */
    private const DOCS = ['*.md', '*.mdx', '*.rst', 'docs/**'];

    /**
     * @param  string  $diff  A unified diff, as `git diff -U0` prints it.
     * @return array{tier:int,reason:string}
     */
    public static function assess(StackSet $stacks, string $diff): array
    {
        $files = [];
        $added = [];   // [file, line]
        $changed = 0;
        $file = null;
        $inHunk = false;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('#^diff --git a/(.+) b/(.+)$#', $line, $m)) {
                // A rename's old path counts too: moving a file out of a tier-3 path changes it
                array_push($files, $m[1], $m[2]);
                $file = $m[2];
                $inHunk = false;
            } elseif (str_starts_with($line, '@@')) {
                $inHunk = true;
            } elseif ($inHunk && str_starts_with($line, '+')) {
                $added[] = [$file, substr($line, 1)];
                $changed++;
            } elseif ($inHunk && str_starts_with($line, '-')) {
                $changed++;
            }
        }

        $files = array_values(array_unique($files));

        foreach ($files as $path) {
            if (Guard::matchesAny($stacks->rules('tier3Paths'), $path, anywhere: true)) {
                return ['tier' => 3, 'reason' => "touches {$path}"];
            }
        }

        foreach ($added as [$path, $line]) {
            foreach ($stacks->rules('riskyCode') as $pattern) {
                if (preg_match($pattern, $line)) {
                    return ['tier' => 3, 'reason' => "adds risky code in {$path} (matches {$pattern})"];
                }
            }
        }

        $docsOrTests = [...self::DOCS, ...$stacks->rules('testFiles')];
        $onlyDocsOrTests = collect($files)->every(fn ($path) => Guard::matchesAny($docsOrTests, $path, anywhere: true));

        return $onlyDocsOrTests && $changed < self::TIER1_MAX_LINES
            ? ['tier' => 1, 'reason' => "docs or tests only, {$changed} changed lines"]
            : ['tier' => 2, 'reason' => "{$changed} changed lines"];
    }
}
