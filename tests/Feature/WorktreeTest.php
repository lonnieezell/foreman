<?php

namespace Tests\Feature;

use App\Services\Worktree;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/** Worktree against real git: a bare "origin", the repository's checkout cloned from it, and a second clone that pushes to it. */
class WorktreeTest extends TestCase
{
    private string $dir;

    private string $origin;

    /** The repository's own checkout, where worktrees are added. */
    private string $source;

    /** Someone else's clone, for landing commits on origin. */
    private string $other;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foreman-worktree-'.uniqid();
        $this->origin = "{$this->dir}/origin.git";
        $this->source = "{$this->dir}/source";
        $this->other = "{$this->dir}/other";
        $this->path = "{$this->dir}/worktrees/issue-7";

        File::ensureDirectoryExists($this->dir);
        $this->git($this->dir, 'init', '-q', '--bare', '-b', 'main', $this->origin);
        $this->clone($this->other);
        $this->commit($this->other, 'a.txt', "one\ntwo\nthree\n", 'base');
        $this->git($this->other, 'push', '-q', 'origin', 'main');
        $this->clone($this->source);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function git(string $cwd, string ...$args): string
    {
        return (new SymfonyProcess(['git', ...$args], $cwd))->mustRun()->getOutput();
    }

    private function clone(string $to): void
    {
        $this->git($this->dir, 'clone', '-q', $this->origin, $to);
        $this->git($to, 'config', 'user.name', 'T');
        $this->git($to, 'config', 'user.email', 't@example.test');
        $this->git($to, 'config', 'commit.gpgsign', 'false');
    }

    private function commit(string $cwd, string $file, string $contents, string $subject): void
    {
        File::ensureDirectoryExists(dirname("{$cwd}/{$file}"));
        File::put("{$cwd}/{$file}", $contents);
        $this->git($cwd, 'add', '-A');
        $this->git($cwd, 'commit', '-q', '-m', $subject);
    }

    private function worktree(): Worktree
    {
        return new Worktree($this->path, 'agent/issue-7', 'main', $this->source);
    }

    /** A worktree on a fresh branch from main. */
    private function prepared(): Worktree
    {
        $worktree = $this->worktree();
        $worktree->prepare(continuing: false);

        return $worktree;
    }

    private function subject(string $ref = 'HEAD'): string
    {
        return trim($this->git($this->path, 'log', '-1', '--format=%s', $ref));
    }

    public function test_prepare_builds_the_branch_from_the_base(): void
    {
        $this->commit($this->other, 'b.txt', "b\n", 'landed after the clone');
        $this->git($this->other, 'push', '-q', 'origin', 'main');

        $pushed = $this->worktree()->prepare(continuing: false);

        $this->assertFalse($pushed);
        $this->assertSame('agent/issue-7', trim($this->git($this->path, 'branch', '--show-current')));
        $this->assertSame('landed after the clone', $this->subject(), 'it fetches first');
    }

    public function test_prepare_replaces_a_worktree_left_behind(): void
    {
        $this->prepared();
        File::put("{$this->path}/leftover.txt", 'x');

        $this->worktree()->prepare(continuing: false);

        $this->assertFileDoesNotExist("{$this->path}/leftover.txt");
    }

    public function test_a_continuing_prepare_rebuilds_from_the_pushed_branch(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'c.txt', "c\n", 'agent work');
        $worktree->push();
        $worktree->remove();

        $this->assertTrue($this->worktree()->prepare(continuing: true));
        $this->assertSame('agent work', $this->subject());
    }

    public function test_a_continuing_prepare_with_nothing_pushed_starts_from_the_base(): void
    {
        $this->assertFalse($this->worktree()->prepare(continuing: true));
        $this->assertSame('base', $this->subject());
    }

    public function test_a_failed_step_throws_with_its_label(): void
    {
        $this->git($this->source, 'remote', 'set-url', 'origin', "{$this->dir}/missing.git");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fetching origin failed');

        $this->worktree()->prepare(continuing: false);
    }

    public function test_tracking_sees_each_labelled_step_and_each_command(): void
    {
        $labels = [];
        $ended = 0;

        $this->worktree()->tracking(function (string $label) use (&$labels) {
            $labels[] = $label;
        }, function () use (&$ended) {
            $ended++;
        })->prepare(continuing: false);

        $this->assertSame(['Fetching origin', 'Creating worktree on agent/issue-7 from origin/main'], $labels);
        $this->assertGreaterThanOrEqual(2, $ended);
    }

    public function test_is_pushed_follows_origin(): void
    {
        $worktree = $this->prepared();

        $this->assertFalse($worktree->isPushed());

        $worktree->push();

        $this->assertTrue($worktree->isPushed());
        $this->assertSame('base', trim($this->git($this->origin, 'log', '-1', '--format=%s', 'agent/issue-7')));
    }

    public function test_catching_up_merges_the_base_and_lists_what_came_in(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'c.txt', "c\n", 'agent work');
        $this->commit($this->other, 'b.txt', "b\n", 'fix(tests): a fix on main');
        $this->git($this->other, 'push', '-q', 'origin', 'main');
        $logged = [];

        $merged = $worktree->catchUpWithBase(fetch: true, log: function (string $m) use (&$logged) {
            $logged[] = $m;
        });

        $this->assertSame(['fix(tests): a fix on main'], $merged);
        $this->assertFileExists("{$this->path}/b.txt");
        $this->assertSame(['Merging origin/main into agent/issue-7'], $logged);
    }

    public function test_a_conflicting_merge_is_aborted_and_the_branch_left_as_it_was(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'a.txt', "agent\n", 'agent work');
        $this->commit($this->other, 'a.txt', "main\n", 'main work');
        $this->git($this->other, 'push', '-q', 'origin', 'main');
        $logged = [];

        $merged = $worktree->catchUpWithBase(fetch: true, log: function (string $m) use (&$logged) {
            $logged[] = $m;
        });

        $this->assertSame([], $merged);
        $this->assertSame('agent work', $this->subject());
        $this->assertSame('', trim($this->git($this->path, 'status', '--porcelain')));
        $this->assertStringContainsString("Couldn't merge origin/main", end($logged));
        $this->assertStringContainsString('CONFLICT', end($logged));
    }

    public function test_uncommitted_changes_skip_the_merge(): void
    {
        $worktree = $this->prepared();
        File::put("{$this->path}/a.txt", "half done\n");
        $this->commit($this->other, 'b.txt', "b\n", 'main work');
        $this->git($this->other, 'push', '-q', 'origin', 'main');
        $logged = [];

        $merged = $worktree->catchUpWithBase(fetch: true, log: function (string $m) use (&$logged) {
            $logged[] = $m;
        });

        $this->assertSame([], $merged);
        $this->assertFileDoesNotExist("{$this->path}/b.txt");
        $this->assertStringEqualsFile("{$this->path}/a.txt", "half done\n");
        $this->assertStringContainsString('uncommitted changes', $logged[0]);
    }

    public function test_a_failed_fetch_skips_the_merge(): void
    {
        $worktree = $this->prepared();
        $this->git($this->source, 'remote', 'set-url', 'origin', "{$this->dir}/missing.git");
        $logged = [];

        $merged = $worktree->catchUpWithBase(fetch: true, log: function (string $m) use (&$logged) {
            $logged[] = $m;
        });

        $this->assertSame([], $merged);
        $this->assertStringContainsString("Couldn't fetch origin", $logged[0]);
    }

    public function test_catching_up_without_fetching_uses_what_was_fetched(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->other, 'b.txt', "b\n", 'not fetched yet');
        $this->git($this->other, 'push', '-q', 'origin', 'main');

        $this->assertSame([], $worktree->catchUpWithBase(fetch: false, log: fn () => null));
        $this->assertFileDoesNotExist("{$this->path}/b.txt");
    }

    public function test_the_diffs_since_the_base_cover_only_the_branch(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'a.txt', "one\nTWO\nthree\n", 'change a line');
        $this->git($this->path, 'mv', 'a.txt', 'moved.txt');
        $this->git($this->path, 'commit', '-q', '-m', 'rename');
        $this->commit($this->path, 'img.bin', "\0\1\2", 'binary');

        $full = $worktree->diffSinceBase();
        $tight = $worktree->diffSinceBase(context: 0);

        $this->assertStringContainsString('+TWO', $full);
        $this->assertStringContainsString("\n one\n", $full, 'git default context');
        $this->assertStringNotContainsString("\n one\n", $tight);
        $this->assertSame(['a.txt', 'img.bin', 'moved.txt'], $worktree->changedFiles());
        $this->assertSame(2, $worktree->changedLines(), 'a rename counts only its changed line; binary counts as none');
    }

    public function test_lines_at_the_base_come_from_the_base_branch_not_the_branch(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'a.txt', "changed\n", 'branch work');

        $this->assertSame(['two', 'three'], $worktree->linesAtBase('a.txt', 2, 3));
        $this->assertSame(['three'], $worktree->linesAtBase('a.txt', 3, 50));
    }

    public function test_lines_at_the_base_are_null_for_a_file_the_base_lacks_or_a_range_past_its_end(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'new.txt', "branch only\n", 'branch work');

        $this->assertNull($worktree->linesAtBase('new.txt', 1, 1));
        $this->assertNull($worktree->linesAtBase('a.txt', 9, 10));
    }

    public function test_lines_at_the_base_are_null_for_a_folder(): void
    {
        $this->commit($this->other, 'src/b.txt', "b\n", 'folder');
        $this->git($this->other, 'push', '-q', 'origin', 'main');

        $this->assertNull($this->prepared()->linesAtBase('src', 1, 5));
    }

    public function test_a_diff_with_no_base_throws(): void
    {
        $worktree = new Worktree($this->prepared()->path, 'agent/issue-7', 'no-such-base', $this->source);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Couldn't diff the change");

        $worktree->diffSinceBase();
    }

    public function test_a_signing_push_without_signing_turned_on_pushes_as_is(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'c.txt', "c\n", 'agent work');
        $head = trim($this->git($this->path, 'rev-parse', 'HEAD'));

        $worktree->push(sign: true);

        $this->assertSame($head, trim($this->git($this->origin, 'rev-parse', 'agent/issue-7')));
    }

    /** Turn signing on with a stand-in for gpg that signs anything, so signing needs no keys. */
    private function fakeSigning(): void
    {
        File::put("{$this->dir}/gpg", "#!/bin/sh\ncat >/dev/null\necho '[GNUPG:] SIG_CREATED D 1 8 00 1700000000 FAKE' >&2\nprintf -- '-----BEGIN PGP SIGNATURE-----\\n\\nfake\\n-----END PGP SIGNATURE-----\\n'\n");
        chmod("{$this->dir}/gpg", 0755);
        $this->git($this->source, 'config', 'gpg.program', "{$this->dir}/gpg");
        $this->git($this->source, 'config', 'commit.gpgsign', 'true');
    }

    private function signed(string $ref): bool
    {
        return str_contains($this->git($this->path, 'cat-file', 'commit', $ref), 'gpgsig');
    }

    public function test_a_signing_push_signs_an_unpushed_branch_from_where_it_left_the_base(): void
    {
        $worktree = $this->prepared();
        $base = trim($this->git($this->path, 'rev-parse', 'origin/main'));
        $this->commit($this->path, 'c.txt', "c\n", 'first');
        $this->commit($this->path, 'd.txt', "d\n", 'second');
        $this->fakeSigning();

        $worktree->push(sign: true);

        $this->assertTrue($this->signed('HEAD'));
        $this->assertTrue($this->signed('HEAD~1'));
        $this->assertSame($base, trim($this->git($this->path, 'rev-parse', 'HEAD~2')), 'the base is not rewritten');
        $this->assertSame(trim($this->git($this->path, 'rev-parse', 'HEAD')), trim($this->git($this->origin, 'rev-parse', 'agent/issue-7')));
    }

    public function test_a_signing_push_leaves_what_was_already_pushed_alone(): void
    {
        $worktree = $this->prepared();
        $this->commit($this->path, 'c.txt', "c\n", 'pushed before');
        $worktree->push();
        $pushed = trim($this->git($this->path, 'rev-parse', 'HEAD'));
        $this->commit($this->path, 'd.txt', "d\n", 'not pushed yet');
        $this->fakeSigning();

        $worktree->push(sign: true);

        $this->assertTrue($this->signed('HEAD'));
        $this->assertSame($pushed, trim($this->git($this->path, 'rev-parse', 'HEAD~1')));
    }

    public function test_a_line_count_that_fails_counts_as_zero(): void
    {
        $worktree = new Worktree($this->prepared()->path, 'agent/issue-7', 'no-such-base', $this->source);

        $this->assertSame(0, $worktree->changedLines());
    }

    public function test_an_existing_origin_head_is_left_alone(): void
    {
        $worktree = $this->prepared();
        $this->git($this->other, 'push', '-q', 'origin', 'main:elsewhere');
        $this->git($this->path, 'fetch', '-q', 'origin');
        $this->git($this->path, 'remote', 'set-head', 'origin', 'elsewhere');

        $worktree->pointOriginHeadAtBase();

        $this->assertSame('refs/remotes/origin/elsewhere', trim($this->git($this->path, 'symbolic-ref', 'refs/remotes/origin/HEAD')));
    }

    public function test_origin_head_gets_pointed_at_the_base_when_missing(): void
    {
        $worktree = $this->prepared();
        $this->git($this->path, 'remote', 'set-head', 'origin', '--delete');

        $worktree->pointOriginHeadAtBase();

        $this->assertSame('refs/remotes/origin/main', trim($this->git($this->path, 'symbolic-ref', 'refs/remotes/origin/HEAD')));
    }

    public function test_excluding_from_git_hides_the_folder_and_writes_the_entry_once(): void
    {
        $worktree = $this->prepared();
        File::ensureDirectoryExists("{$this->path}/.foreman");
        File::put("{$this->path}/.foreman/result.json", '{}');

        $worktree->excludeFromGit('.foreman/');
        $worktree->excludeFromGit('.foreman/');

        $this->assertSame('', trim($this->git($this->path, 'status', '--porcelain')));
        $this->assertSame(1, substr_count((string) file_get_contents("{$this->source}/.git/info/exclude"), "\n.foreman/\n"));
    }

    public function test_git_dirs_are_the_worktrees_own_and_the_shared_one(): void
    {
        [$gitDir, $commonDir] = $this->prepared()->gitDirs();

        $this->assertSame(realpath("{$this->source}/.git/worktrees/issue-7"), realpath($gitDir));
        $this->assertSame(realpath("{$this->source}/.git"), realpath($commonDir));
    }

    public function test_remove_takes_the_worktree_away(): void
    {
        $worktree = $this->prepared();
        File::put("{$this->path}/dirty.txt", 'x');

        $worktree->remove();

        $this->assertDirectoryDoesNotExist($this->path);
        $this->assertStringNotContainsString($this->path, $this->git($this->source, 'worktree', 'list'));
    }
}
