<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Foreman's git work on one run's worktree, the way GitHub is Foreman's work through `gh`.
 * `$source` is the repository's own checkout, where worktrees are added and removed. It shares refs with the worktree.
 */
class Worktree
{
    private ?Closure $before = null;

    private ?Closure $after = null;

    public function __construct(
        public readonly string $path,
        public readonly string $branch,
        public readonly string $base,
        public readonly ?string $source = null,
    ) {}

    /** The base branch as a remote ref, as the diffs and merges name it. */
    public function baseRef(): string
    {
        return "origin/{$this->base}";
    }

    /**
     * Hooks around each step that has a label: `$before` gets the label, `$after` runs when the command ends.
     * The caller uses them to log, check for cancellation and keep the run's heartbeat fresh.
     */
    public function tracking(Closure $before, Closure $after): static
    {
        $this->before = $before;
        $this->after = $after;

        return $this;
    }

    /**
     * Fetch, then build the worktree on its branch. Gives true when it continues what was pushed.
     * A continuing run rebuilds from the pushed branch, and never resets the branch to base.
     */
    public function prepare(bool $continuing): bool
    {
        $this->step('Fetching origin', ['git', 'fetch', 'origin', '--quiet'], $this->source);

        // A previous run for the same issue may have left a worktree behind
        if (File::isDirectory($this->path)) {
            $this->step('Removing old worktree', ['git', 'worktree', 'remove', '--force', $this->path], $this->source);
        }

        $pushed = $continuing && $this->isPushed();
        $from = $pushed ? "origin/{$this->branch}" : $this->baseRef();

        File::ensureDirectoryExists(dirname($this->path));
        $this->step("Creating worktree on {$this->branch} from {$from}",
            ['git', 'worktree', 'add', '-B', $this->branch, $this->path, $from], $this->source);

        return $pushed;
    }

    /** Whether the branch exists on origin, as of the last fetch. */
    public function isPushed(): bool
    {
        return $this->git(['rev-parse', '--verify', '--quiet', "origin/{$this->branch}"], $this->source ?? $this->path)->successful();
    }

    /**
     * Merge the latest base branch into the branch, so fixes that landed there reach it.
     * Any failure leaves the branch as it was, says why through `$log`, and gives no commits.
     *
     * @param  Closure(string):void  $log
     * @return list<string> Subjects of the commits the merge brought in, 20 at most.
     */
    public function catchUpWithBase(bool $fetch, Closure $log): array
    {
        $base = $this->baseRef();

        if ($fetch) {
            $fetched = $this->git(['fetch', 'origin', '--quiet']);

            if ($fetched->failed()) {
                $log("Couldn't fetch origin, so {$base} wasn't merged. Going on from the branch as it was: ".str(trim($fetched->errorOutput()))->limit(500));

                return [];
            }
        }

        // `git merge --abort` can't always restore uncommitted changes, so never risk them
        if (trim($this->git(['status', '--porcelain', '--untracked-files=no'])->output()) !== '') {
            $log("Not merging {$base}: the worktree has uncommitted changes. Going on from the branch as it was.");

            return [];
        }

        $log("Merging {$base} into {$this->branch}");
        $incoming = $this->git(['log', '--format=%s', "HEAD..{$base}"]);

        try {
            // Well under the 5-minute heartbeat limit, so a slow merge can't get the run reaped
            $merge = Process::path($this->path)->timeout(120)->run(['git', 'merge', '--no-edit', $base]);

            if ($merge->successful()) {
                return $incoming->successful()
                    ? array_slice(array_values(array_filter(explode("\n", trim($incoming->output())))), 0, 20)
                    : [];
            }
            $why = trim($merge->errorOutput()."\n".$merge->output());
        } catch (Throwable $e) {
            $why = $e->getMessage();
        }

        $this->git(['merge', '--abort']);
        $log("Couldn't merge {$base}, so the merge was aborted. Going on from the branch as it was: ".str($why)->limit(500));

        return [];
    }

    /** The branch's diff since it left the base branch. `$context` is the lines around each change, or git's default when null. */
    public function diffSinceBase(?int $context = null): string
    {
        return $this->sinceBase(['diff', ...($context === null ? [] : ["-U{$context}"]), '--no-color', '--no-ext-diff'], "Couldn't diff the change");
    }

    /**
     * Paths changed since the branch left the base branch. A rename counts as the old path going and the new one arriving.
     *
     * @return list<string>
     */
    public function changedFiles(): array
    {
        return array_values(array_filter(explode("\0", $this->sinceBase(['diff', '--name-only', '-z', '--no-renames'], 'Could not list the changed files'))));
    }

    /** Lines added plus deleted since the branch left the base branch. */
    public function changedLines(): int
    {
        return (int) collect(explode("\n", trim($this->sinceBase(['diff', '--numstat'], "Couldn't count the changed lines"))))
            ->sum(fn ($row) => array_sum(array_map('intval', array_slice(explode("\t", $row), 0, 2))));   // binary files show "-"
    }

    /** Foreman's own push, with the agent's restrictions left off. A signing push first signs what isn't pushed yet. */
    public function push(bool $sign = false): void
    {
        if ($sign) {
            $this->signUnpushedCommits();
        }

        $this->step("Pushing {$this->branch}", ['git', 'push', '--set-upstream', 'origin', $this->branch]);
    }

    /** `/security-review` diffs against origin/HEAD, and quietly reviews nothing when that ref is missing. */
    public function pointOriginHeadAtBase(): void
    {
        if ($this->git(['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/HEAD'])->failed()) {
            $this->step("Pointing origin/HEAD at {$this->baseRef()} for /security-review", ['git', 'remote', 'set-head', 'origin', $this->base]);
        }
    }

    /** Delete Foreman's files from the worktree, and keep the folder out of git without touching .gitignore. */
    public function clearForemanFiles(string ...$files): void
    {
        File::delete(array_map(fn (string $file) => "{$this->path}/{$file}", $files));

        $exclude = trim($this->git(['rev-parse', '--git-path', 'info/exclude'])->output());

        if ($exclude === '') {
            return;
        }

        $exclude = str_starts_with($exclude, '/') ? $exclude : "{$this->path}/{$exclude}";

        if (! str_contains((string) @file_get_contents($exclude), "\n.foreman/\n")) {
            File::ensureDirectoryExists(dirname($exclude));
            File::append($exclude, "\n.foreman/\n");
        }
    }

    /** The worktree's own git folder and the one it shares with the checkout, as absolute paths. */
    public function gitDirs(): array
    {
        return explode("\n", trim($this->git(['rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'])->throw()->output()));
    }

    /** Remove the worktree. A failure is left alone: the folder is only clutter by then. */
    public function remove(): void
    {
        $this->git(['worktree', 'remove', '--force', $this->path], $this->source);
    }

    /** A sandboxed agent can't reach gpg-agent, so it commits unsigned. Sign what isn't pushed yet, here outside the sandbox. */
    private function signUnpushedCommits(): void
    {
        if (trim($this->git(['config', '--type=bool', '--get', 'commit.gpgsign'])->output()) !== 'true') {
            return;
        }

        $from = $this->isPushed()
            ? "origin/{$this->branch}"
            : trim($this->git(['merge-base', 'HEAD', $this->baseRef()])->output());
        $this->step('Signing the agent\'s commits', ['git', 'rebase', '--force-rebase', '--rebase-merges', '--autostash', '--gpg-sign', $from]);
    }

    /** Run a diff against the base branch and give its output. */
    private function sinceBase(array $args, string $failure): string
    {
        $diff = $this->git([...$args, "{$this->baseRef()}...HEAD"]);

        if ($diff->failed()) {
            throw new RuntimeException("{$failure}: ".str(trim($diff->errorOutput()))->limit(500));
        }

        return $diff->output();
    }

    private function git(array $args, ?string $cwd = null, int $timeout = 60): ProcessResult
    {
        $result = Process::path($cwd ?? $this->path)->timeout($timeout)->run(['git', ...$args]);

        if ($this->after) {
            ($this->after)();
        }

        return $result;
    }

    private function step(string $label, array $command, ?string $cwd = null): void
    {
        if ($this->before) {
            ($this->before)($label);
        }

        $result = $this->git(array_slice($command, 1), $cwd, 300);

        if ($result->failed()) {
            $output = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException("{$label} failed: ".str($output)->limit(500));
        }
    }
}
