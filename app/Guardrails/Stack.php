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

    /** @return list<string> Shell commands, each starting with a `cd` when the stack is not at the root. */
    abstract public function verifyCommands(): array;

    /** Changes here are high risk (tier 3). */
    abstract public function tier3Paths(): array;

    /** Regexes. Added lines matching one are high risk (tier 3). */
    abstract public function riskyCode(): array;

    /** The rule tables a stack always fills, by method name. */
    public const RULES = [
        'protectedConfig', 'lockFiles', 'testFiles', 'testMarkers', 'assertionMarkers',
        'skipMarkers', 'tier3Paths', 'riskyCode',
    ];

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

    /** Run `$command` inside `$dir`. */
    protected function inDir(string $dir, string $command): string
    {
        return $dir === '' ? $command : 'cd '.escapeshellarg($dir).' && '.$command;
    }

    /** The decoded composer.json or package.json in `$dir`. */
    protected function json(string $dir, string $file): array
    {
        return json_decode($this->read($dir, $file), true) ?: [];
    }
}
