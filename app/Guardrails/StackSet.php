<?php

namespace App\Guardrails;

use App\Guardrails\Stacks\Php;
use App\Guardrails\Stacks\Python;
use App\Guardrails\Stacks\Rust;
use App\Guardrails\Stacks\TypeScript;
use Symfony\Component\Finder\Finder;

/** The stacks found in one worktree. Their rules are the union of each stack's rules. */
class StackSet
{
    /** Marker files, by stack class. A `*` matches any characters. */
    private const MARKERS = [
        Php::class => ['composer.json'],
        TypeScript::class => ['package.json'],
        Rust::class => ['Cargo.toml'],
        Python::class => ['pyproject.toml', 'requirements*.txt', 'setup.py'],
    ];

    /** Folders that hold other people's code. Dot folders are skipped too. */
    private const SKIPPED_DIRS = ['vendor', 'node_modules', 'target', 'venv', '__pycache__'];

    /** @param list<Stack> $stacks */
    public function __construct(public readonly array $stacks) {}

    /**
     * Look for marker files anywhere in the worktree, so a Tauri app's `src-tauri/Cargo.toml` counts.
     * Git-ignored files don't count: they aren't the repo's code, such as Foreman's own worktrees of other repos.
     */
    public static function detect(string $root): self
    {
        $stacks = [];

        foreach (self::MARKERS as $class => $names) {
            $dirs = [];

            foreach (Finder::create()->files()->in($root)->name($names)->exclude(self::SKIPPED_DIRS)->ignoreVCSIgnored(true) as $file) {
                $dirs[] = str_replace('\\', '/', $file->getRelativePath());
            }

            if ($dirs) {
                $stacks[] = new $class($root, array_values(array_unique($dirs)));
            }
        }

        return new self($stacks);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (Stack $stack) => $stack->name(), $this->stacks);
    }

    /**
     * The union of one rule table across all stacks.
     *
     * @param  string  $rule  A rule table method on `Stack`, such as `tier3Paths`.
     * @return list<string>
     */
    public function rules(string $rule): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (Stack $stack) => $stack->{$rule}(), $this->stacks))));
    }

    /**
     * Every stack's verify commands, with format checks limited to files changed since `$base`.
     *
     * @return list<string>
     */
    public function verifyCommands(string $base): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (Stack $stack) => $stack->verifyCommands($base), $this->stacks))));
    }
}
