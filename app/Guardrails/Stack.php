<?php

namespace App\Guardrails;

/**
 * The guardrail rules for one language stack in a worktree.
 *
 * Path patterns are globs relative to a marker folder. Markers are plain
 * strings to look for in code. Risky-code patterns are PCRE regexes.
 *
 * A stack can sit in several folders (a monorepo, or a Tauri app's nested
 * Cargo project), so `$dirs` lists each one relative to `$root`, with ''
 * for the root itself.
 */
abstract class Stack
{
    /** @param list<string> $dirs */
    public function __construct(protected string $root, protected array $dirs) {}

    abstract public function name(): string;

    /** Files that carry the stack's tool settings. The agent may not edit them. */
    abstract public function protectedConfig(): array;

    /** Lock files. Editing them is allowed when the issue carries a `dependencies` label. */
    abstract public function lockFiles(): array;

    abstract public function testFiles(): array;

    /** What counts as one test, so a drop in the count can be caught. */
    abstract public function testMarkers(): array;

    /** What counts as one assertion, so a drop in the count can be caught. */
    abstract public function assertionMarkers(): array;

    /** Added lines holding one of these fail the integrity check. */
    abstract public function skipMarkers(): array;

    /** The command that formats staged files at commit time, or null when the repo has none. */
    abstract public function formatter(): ?string;

    /** @return list<string> File extensions, without the dot, that the formatter handles. */
    abstract public function formatExtensions(): array;

    /**
     * @param  string  $base  The ref the work branched from, such as `origin/main`. Format checks only look at files changed since it.
     * @return list<string> Shell commands. When the stack is not at the root, each is a subshell that starts with a `cd`.
     */
    abstract public function verifyCommands(string $base): array;

    /**
     * The GitHub Actions steps for the CI kit, as arrays with `name` plus `run` or `uses`.
     * The groups run in the order setup, lint, test, audit, with the integrity check between lint and test.
     *
     * @return array{setup: list<array>, lint: list<array>, test: list<array>, audit: list<array>}
     */
    abstract public function ciSteps(): array;

    /** The folder the CI job runs in: the shallowest folder holding the stack. */
    public function ciDir(): string
    {
        $dirs = $this->dirs;
        usort($dirs, fn (string $a, string $b) => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return $dirs[0];
    }

    /** `$file` in the CI folder, relative to the repo root. `uses:` steps need this, because they ignore the job's working directory. */
    protected function ciPath(string $file): string
    {
        return ltrim($this->ciDir()."/{$file}", '/');
    }

    /** Changes here are high risk (tier 3). */
    abstract public function tier3Paths(): array;

    /** Regexes. Added lines matching one are high risk (tier 3). */
    abstract public function riskyCode(): array;

    /** The rule tables a stack always fills, by method name. */
    public const RULES = [
        'protectedConfig', 'lockFiles', 'testFiles', 'testMarkers', 'assertionMarkers',
        'skipMarkers', 'tier3Paths', 'riskyCode',
    ];

    /**
     * CodeQL for public repos. Private repos need GitHub Advanced Security for it, so they run Semgrep instead.
     * A private repo that has Advanced Security can delete the two `if` lines.
     *
     * @return list<array>
     */
    protected function codeqlOrSemgrep(string $language, string ...$semgrepConfigs): array
    {
        return [
            ['name' => 'CodeQL init', 'if' => '${{ !github.event.repository.private }}', 'uses' => 'github/codeql-action/init@v3', 'with' => ['languages' => $language]],
            ['name' => 'CodeQL analyze', 'if' => '${{ !github.event.repository.private }}', 'uses' => 'github/codeql-action/analyze@v3'],
            $this->semgrep($semgrepConfigs, '${{ github.event.repository.private }}'),
        ];
    }

    /** @param list<string> $configs */
    protected function semgrep(array $configs, ?string $if = null): array
    {
        $flags = implode(' ', array_map(fn (string $config) => "--config {$config}", $configs));

        return array_filter([
            'name' => 'Semgrep',
            'if' => $if,
            // pipx, because ubuntu-latest's system Python refuses a plain pip install (PEP 668)
            'run' => "pipx run semgrep scan {$flags} --error",
        ]);
    }

    protected function path(string $dir, string $file): string
    {
        return rtrim("{$this->root}/{$dir}", '/')."/{$file}";
    }

    protected function read(string $dir, string $file): string
    {
        $path = $this->path($dir, $file);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    protected function has(string $dir, string $file): bool
    {
        return is_file($this->path($dir, $file));
    }

    /** Run `$command` inside `$dir`. The subshell keeps the `cd` from leaking into later lines of the script. */
    protected function inDir(string $dir, string $command): string
    {
        return $dir === '' ? $command : '(cd '.escapeshellarg($dir).' && '.$command.')';
    }

    /**
     * Run `$command` on the files with the formatter's extensions changed since `$base`: committed, staged,
     * unstaged and untracked, relative to the folder. Runs nothing when none changed, and fails when `$base` is unknown.
     */
    protected function onChangedFiles(string $base, string $command): string
    {
        $globs = implode(' ', array_map(fn ($ext) => escapeshellarg("*.{$ext}"), $this->formatExtensions()));

        return '(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base '.escapeshellarg($base)." -- {$globs}"
            ." && git ls-files -z --others --exclude-standard -- {$globs}; } | xargs -0 -r {$command} --)";
    }

    /** The decoded composer.json or package.json in `$dir`. */
    protected function json(string $dir, string $file): array
    {
        return json_decode($this->read($dir, $file), true) ?: [];
    }
}
