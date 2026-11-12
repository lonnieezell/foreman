<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Dashboard;
use App\Livewire\RunDetail;
use App\Models\PermissionRequest;
use App\Models\Repository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The operator answers the agent's calls off the allowlist from the run page (ADR 0010). */
class PermissionPromptTest extends TestCase
{
    use RefreshDatabase;

    private function liveRun(): Run
    {
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'enabled' => false,
            'allowed_tools' => 'Read,Edit', 'setup_script' => 'true',
        ]);

        return $repo->runs()->create([
            'issue_number' => 7, 'issue_title' => 'Export', 'issue_url' => 'https://example.test/7',
            'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => RunStatus::Working, 'pid' => 123,
        ]);
    }

    private function ask(Run $run, string $tool = 'Bash', array $input = ['command' => './vault test --compact x']): PermissionRequest
    {
        return $run->permissionRequests()->create([
            'request_id' => 'req-1', 'tool_name' => $tool, 'input' => $input, 'expires_at' => now()->addMinutes(3),
        ]);
    }

    public function test_the_run_page_shows_a_waiting_call_with_a_suggested_pattern(): void
    {
        $run = $this->liveRun();
        $request = $this->ask($run);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->assertSee('The agent wants to run a command that isn’t on the allowlist')
            ->assertSee('./vault test --compact x')
            ->assertSet("patterns.{$request->id}", 'Bash(./vault:*)')
            ->assertSee(['Allow once', 'Always allow', 'Deny']);
    }

    public static function answers(): array
    {
        return [
            'allow once' => ['allowOnce', PermissionRequest::ALLOW],
            'deny' => ['deny', PermissionRequest::DENY],
        ];
    }

    #[DataProvider('answers')]
    public function test_the_operators_answer_is_saved_and_the_allowlist_is_left_alone(string $action, string $decision): void
    {
        $run = $this->liveRun();
        $request = $this->ask($run);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->call($action, $request->id)
            ->assertDontSee('Allow once');

        $this->assertSame($decision, $request->fresh()->decision);
        $this->assertSame('Read,Edit', $run->repository->fresh()->allowed_tools);
    }

    public function test_always_allow_adds_the_operators_pattern_to_the_repositorys_allowlist(): void
    {
        $run = $this->liveRun();
        $request = $this->ask($run);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->set("patterns.{$request->id}", ' Bash(./vault test:*) ')
            ->call('alwaysAllow', $request->id);

        $this->assertSame(PermissionRequest::ALWAYS, $request->fresh()->decision);
        $this->assertSame('Bash(./vault test:*)', $request->fresh()->pattern);
        $this->assertSame('Read,Edit,Bash(./vault test:*)', $run->repository->fresh()->allowed_tools);
    }

    public function test_a_pattern_with_a_comma_or_nothing_in_it_is_refused(): void
    {
        $run = $this->liveRun();
        $request = $this->ask($run);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->set("patterns.{$request->id}", 'Bash(a:*),Bash')
            ->call('alwaysAllow', $request->id)
            ->assertHasErrors("patterns.{$request->id}")
            ->set("patterns.{$request->id}", '  ')
            ->call('alwaysAllow', $request->id)
            ->assertHasErrors("patterns.{$request->id}");

        $this->assertNull($request->fresh()->decision);
        $this->assertSame('Read,Edit', $run->repository->fresh()->allowed_tools);
    }

    public function test_an_answer_after_the_call_timed_out_changes_nothing(): void
    {
        $run = $this->liveRun();
        $request = $this->ask($run);
        $request->decide(PermissionRequest::TIMEOUT);

        Livewire::test(RunDetail::class, ['run' => $run])
            ->set("patterns.{$request->id}", 'Bash(./vault:*)')
            ->call('alwaysAllow', $request->id)
            ->assertSet('flash', 'That call was already answered, or it timed out.');

        $this->assertSame(PermissionRequest::TIMEOUT, $request->fresh()->decision);
        $this->assertSame('Read,Edit', $run->repository->fresh()->allowed_tools);
    }

    public function test_the_dashboard_flags_a_run_waiting_for_approval(): void
    {
        $run = $this->liveRun();

        Livewire::test(Dashboard::class)->assertDontSee('needs approval');

        $this->ask($run);

        Livewire::test(Dashboard::class)->assertSee('needs approval');
    }
}
