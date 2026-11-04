<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class NativeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Process::fake();
        Setting::put('native_notifications', 1);
    }

    protected function tearDown(): void
    {
        Run::all()->each(fn (Run $run) => File::delete($run->logPath()));

        parent::tearDown();
    }

    private function makeRun(RunStatus $status = RunStatus::Working): Run
    {
        $repo = Repository::create([
            'name' => 'app',
            'local_path' => base_path(),
            'github_repo' => 'owner/app',
            'enabled' => false,
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS,
            'setup_script' => 'true',
        ]);

        $run = $repo->runs()->create([
            'issue_number' => 42, 'issue_title' => 'Title', 'issue_url' => 'https://example.test/issues/42',
            'branch' => 'agent/issue-42', 'model' => 'sonnet', 'status' => $status,
        ]);
        File::delete($run->logPath());   // ids restart each test, so a log could be left from another one

        return $run;
    }

    /** One osascript call, with the title and `$body` after `--`. */
    private function assertNotified(string $body): void
    {
        Process::assertRanTimes(fn (PendingProcess $process) => array_slice($process->command, -3) === ['--', 'Foreman: app #42', $body], 1);
    }

    public function test_a_move_to_needs_you_sends_the_repository_issue_and_stop_reason(): void
    {
        $this->makeRun()->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertNotified('Needs you: gate failed');
    }

    public function test_nothing_is_sent_while_the_setting_is_off(): void
    {
        Setting::put('native_notifications', 0);

        $this->makeRun()->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        Process::assertNothingRan();
    }

    public function test_only_a_change_to_needs_you_sends_one(): void
    {
        $this->makeRun()->moveTo(RunStatus::Failed);
        $this->makeRun(RunStatus::NeedsHuman)->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        Process::assertNothingRan();
    }

    public function test_agent_text_is_passed_as_one_literal_argument_after_the_end_of_options(): void
    {
        $run = $this->makeRun();
        $run->messages()->create(['role' => 'agent', 'body' => '-e do shell script "touch /tmp/pwned" " \\ $(whoami)']);

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'question']);

        Process::assertRan(fn (PendingProcess $process) => $process->command === [
            'osascript', '-e', 'on run argv', '-e', 'display notification (item 2 of argv) with title (item 1 of argv)', '-e', 'end run',
            '--', 'Foreman: app #42', 'Question: -e do shell script "touch /tmp/pwned" " \\ $(whoami)',
        ]);
    }

    public function test_a_question_shows_the_first_question_shortened(): void
    {
        $run = $this->makeRun();
        $run->messages()->createMany([
            ['role' => 'agent', 'body' => 'Should the export include archived repositories, or only the active ones that still have runs this month?'],
            ['role' => 'agent', 'body' => 'Second question'],
        ]);

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'question']);

        $this->assertNotified('Question: Should the export include archived repositories, or only the active ones that still have...');
    }

    public function test_a_question_on_several_lines_is_shown_on_one(): void
    {
        $run = $this->makeRun();
        $run->messages()->create(['role' => 'agent', 'body' => "\n## Scope\n\nShould archived repositories be included?"]);

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'question']);

        $this->assertNotified('Question: ## Scope Should archived repositories be included?');
    }

    public function test_a_failed_notification_is_logged_but_not_as_a_run_error_and_the_move_still_happens(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Not authorised to send Apple events', exitCode: 1)]);
        $run = $this->makeRun();

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame(RunStatus::NeedsHuman, $run->fresh()->status);
        $this->assertSame('macOS notification not sent: Not authorised to send Apple events', collect($run->logEntries())->last()['text']);
        $this->assertSame('message', collect($run->logEntries())->last()['kind']);
    }

    public function test_a_notification_that_throws_is_logged_and_does_not_fail_the_run(): void
    {
        Process::fake(fn () => throw new \RuntimeException('The process exceeded the timeout of 5 seconds.'));
        $run = $this->makeRun();

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame(RunStatus::NeedsHuman, $run->fresh()->status);
        $this->assertStringContainsString('macOS notification not sent: The process exceeded the timeout of 5 seconds.', $run->tail());
    }
}
