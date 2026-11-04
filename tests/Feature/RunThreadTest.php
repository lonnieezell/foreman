<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\RunDetail;
use App\Models\Repository;
use App\Models\Run;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The run page's thread: the agent's questions and the operator's answers, kept in Foreman (ADR 0001). */
class RunThreadTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $attributes = []): Run
    {
        $repo = Repository::firstOrCreate(['name' => 'app'], [
            'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ]);

        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Export', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::NeedsHuman, 'stop_reason' => 'question',
            'session_id' => 'abc', 'worktree_path' => '/tmp/wt', 'finished_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_the_thread_follows_the_chain_of_runs_in_order_with_the_round_and_total_cost(): void
    {
        $first = $this->makeRun(['cost_usd' => 0.5]);
        $first->messages()->create(['role' => 'agent', 'body' => 'Include <b>archived</b> rows?']);
        $first->messages()->create(['role' => 'human', 'body' => "Yes.\nFlag them."]);
        $second = $this->makeRun(['cost_usd' => 0.25, 'previous_run_id' => $first->id]);
        $second->messages()->create(['role' => 'agent', 'body' => 'Which flag?']);

        Livewire::test(RunDetail::class, ['run' => $second])
            ->assertSeeInOrder(['Round 2', '$0.75', 'Include <b>archived</b> rows?', "Yes.\nFlag them.", 'Which flag?'])
            ->assertSeeHtml('Include &lt;b&gt;archived&lt;/b&gt; rows?')
            ->assertDontSeeHtml('<b>archived</b>');
    }

    public function test_a_run_with_no_messages_and_still_active_has_no_thread(): void
    {
        $run = $this->makeRun(['status' => RunStatus::Working, 'stop_reason' => null, 'finished_at' => null]);

        Livewire::test(RunDetail::class, ['run' => $run])->assertDontSee('Thread');
    }

    public function test_posting_on_any_finished_run_saves_the_message_without_resuming(): void
    {
        $run = $this->makeRun(['status' => RunStatus::Failed, 'stop_reason' => null]);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSeeHtml('wire:submit="postMessage"')
            ->set('message', "  Use the v2 API.\nNot v1.  ")
            ->call('postMessage')
            ->assertSet('message', '')
            ->assertSee("Use the v2 API.\nNot v1.");

        $this->assertSame([['human', "Use the v2 API.\nNot v1."]], $run->messages->map(fn ($m) => [$m->role, $m->body])->all());
        $this->assertSame(1, Run::count());
    }

    public function test_a_blank_message_is_not_saved(): void
    {
        $run = $this->makeRun();

        Livewire::test(RunDetail::class, ['run' => $run])->set('message', '   ')->call('postMessage')->assertHasErrors('message');

        $this->assertSame(0, $run->messages()->count());
    }

    public static function runsWithoutABox(): array
    {
        return [
            'active' => [['status' => RunStatus::Working, 'stop_reason' => null, 'finished_at' => null]],
            'handed over with a PR' => [['stop_reason' => 'rounds_ran_out', 'pr_url' => 'https://github.com/owner/app/pull/3']],
        ];
    }

    #[DataProvider('runsWithoutABox')]
    public function test_an_active_run_or_one_with_a_pr_has_no_message_box_and_takes_no_message(array $attributes): void
    {
        $run = $this->makeRun($attributes);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertDontSeeHtml('wire:submit="postMessage"')
            ->set('message', 'Hello')
            ->call('postMessage');

        $this->assertSame(0, $run->messages()->count());
    }

    public function test_continue_on_a_question_run_asks_for_a_confirm_only_while_nothing_answers_the_question(): void
    {
        $run = $this->makeRun();
        $run->messages()->create(['role' => 'agent', 'body' => 'Include archived rows?']);

        Livewire::test(RunDetail::class, ['run' => $run])->assertSee('You haven’t answered yet.');

        $run->messages()->create(['role' => 'human', 'body' => 'Yes.']);

        Livewire::test(RunDetail::class, ['run' => $run])->assertDontSee('You haven’t answered yet.');
    }

    public function test_continue_starts_one_resume_and_a_second_click_or_tab_gets_an_error(): void
    {
        $run = $this->makeRun();
        $github = Mockery::mock(GitHub::class);
        $github->expects('reclaim')->once();
        $this->app->instance(GitHub::class, $github);
        Queue::fake();

        $first = Livewire::test(RunDetail::class, ['run' => $run]);
        $second = Livewire::test(RunDetail::class, ['run' => $run]);

        $first->call('continueRun')->assertRedirect();
        $second->call('continueRun')->assertNoRedirect()->assertSee('This run can’t be continued. It may have been continued already.');

        $this->assertSame(1, Run::where('previous_run_id', $run->id)->count());
    }

    public static function laterRuns(): array
    {
        return [
            'continued' => [true],
            'started over' => [false],
        ];
    }

    /** Only the newest run's messages reach the next resume, and two runs must never share the worktree. */
    #[DataProvider('laterRuns')]
    public function test_once_the_issue_has_a_later_run_the_old_run_takes_no_message_and_cannot_be_continued(bool $continued): void
    {
        $run = $this->makeRun();
        $this->makeRun(['status' => RunStatus::Working, 'stop_reason' => null, 'finished_at' => null, 'previous_run_id' => $continued ? $run->id : null]);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertDontSeeHtml('wire:click="continueRun"')
            ->assertDontSeeHtml('wire:submit="postMessage"')
            ->set('message', 'Late answer')
            ->call('postMessage');

        $this->assertFalse($run->canContinueAfterReply());
        $this->assertSame(0, $run->messages()->count());
    }
}
