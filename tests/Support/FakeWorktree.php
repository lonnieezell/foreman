<?php

namespace Tests\Support;

use App\Services\Worktree;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Stands in for the Worktree in job tests, so they say what git answers and which Worktree methods ran,
 * not which git commands. Worktree's own tests run real git.
 */
class FakeWorktree extends Worktree
{
    /** @var list<string> Names of the methods that ran, in order. A push that signs is "push:sign". */
    public array $calls = [];

    /** @var list<string> Subjects the base merge brought in. */
    public array $merged = [];

    /** @var list<string> Messages the base merge reports through its log. */
    public array $catchUpLog = [];

    public bool $pushed = false;

    public string $diff = '';

    /** The message of the exception a diff throws, when set. */
    public ?string $diffFailure = null;

    public int $changedLines = 0;

    /** @var list<string> What changedFiles gives. */
    public array $changedFiles = [];

    /** The message of the exception a push throws, when set. */
    public ?string $pushFailure = null;

    /** What head gives. */
    public string $head = 'aaaaaaa1111';

    /** @var array{base:string,conflicts:list<string>} What mergeInBase gives. */
    public array $merge = ['base' => 'bbbbbbb0000', 'conflicts' => ['app/Foo.php']];

    /** The message of the exception mergeInBase throws, when set. */
    public ?string $mergeFailure = null;

    /** What contains gives. */
    public bool $containsBase = true;

    /** @var list<string> What markersAt gives. */
    public array $markers = [];

    /** @var array{ours:string,theirs:string} What conflictSides gives. */
    public array $sides = ['ours' => '+ours', 'theirs' => '+theirs'];

    /** @var array<string,list<string>> What linesAtBase gives for each path, whatever the range. A path left out isn't on the base branch. */
    public array $atBase = [];

    /** Runs when the worktree is removed, for tests that check the order against other commands. */
    public ?Closure $onRemove = null;

    /** What the Worktree was built with, the last time the container made one. */
    public ?array $builtWith = null;

    public function __construct()
    {
        parent::__construct('', '', '', '');
    }

    /** Makes the container hand out this fake wherever the app builds a Worktree. */
    public static function install(Application $app): self
    {
        $fake = new self;
        $app->bind(Worktree::class, function ($app, array $params) use ($fake) {
            $fake->builtWith = $params;

            return $fake;
        });

        return $fake;
    }

    public function ran(string $call): bool
    {
        return in_array($call, $this->calls, true);
    }

    public function didPush(): bool
    {
        return $this->ran('push') || $this->ran('push:sign');
    }

    public function baseRef(): string
    {
        return 'origin/'.($this->builtWith['base'] ?? 'main');
    }

    public function prepare(bool $continuing): bool
    {
        $this->calls[] = 'prepare';

        return $continuing && $this->pushed;
    }

    public function catchUpWithBase(bool $fetch, Closure $log): array
    {
        $this->calls[] = $fetch ? 'catchUp:fetch' : 'catchUp';
        array_map($log, $this->catchUpLog);

        return $this->merged;
    }

    public function diffSinceBase(?int $context = null): string
    {
        $this->calls[] = 'diff';

        if ($this->diffFailure !== null) {
            throw new RuntimeException($this->diffFailure);
        }

        return $this->diff;
    }

    public function linesAtBase(string $path, int $start, int $end): ?array
    {
        $this->calls[] = 'linesAtBase';

        return $this->atBase[$path] ?? null;
    }

    public function changedFiles(): array
    {
        $this->calls[] = 'changedFiles';

        return $this->changedFiles;
    }

    public function changedLines(): int
    {
        $this->calls[] = 'changedLines';

        return $this->changedLines;
    }

    public function push(bool $sign = false): void
    {
        if ($this->pushFailure !== null) {
            throw new RuntimeException($this->pushFailure);
        }

        $this->calls[] = $sign ? 'push:sign' : 'push';
    }

    public function head(): string
    {
        return $this->head;
    }

    public function mergeInBase(): array
    {
        $this->calls[] = 'mergeInBase';

        if ($this->mergeFailure !== null) {
            throw new RuntimeException($this->mergeFailure);
        }

        return $this->merge;
    }

    public function contains(string $sha): bool
    {
        return $this->containsBase;
    }

    public function markersAt(array $paths): array
    {
        return $this->markers;
    }

    public function conflictSides(array $paths): array
    {
        return $this->sides;
    }

    public function pointOriginHeadAtBase(): void
    {
        $this->calls[] = 'pointOriginHead';
    }

    public function excludeFromGit(string $entry): void
    {
        $this->calls[] = 'excludeFromGit';
    }

    public function gitDirs(): array
    {
        return ['/repo/.git/worktrees/issue-7', '/repo/.git'];
    }

    public function remove(): void
    {
        $this->calls[] = 'remove';

        if ($this->onRemove) {
            ($this->onRemove)();
        }
    }
}
