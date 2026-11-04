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

    protected function tearDown(): void
    {
        Run::all()->each(fn (Run $run) => File::delete($run->logPath()));

        parent::tearDown();
    }

    /** @return list<string> The title and body passed to osascript after `--`. */
    private function notifiedWith(): array
    {
        $sent = [];

        Process::assertRan(function (PendingProcess $process) use (&$sent) {
            if (! is_array($process->command) || $process->command[0] !== 'osascript') {
                return false;
            }
            $sent = array_slice($process->command, array_search('--', $process->command, true) + 1);

            return true;
        });

        return $sent;
    }

    public function test_a_move_to_needs_you_sends_the_repository_issue_and_stop_reason(): void
    {
        Process::fake();
        Setting::put('native_notifications', 1);

        $this->makeRun()->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame(['Foreman: app #42', 'Needs you: gate failed'], $this->notifiedWith());
    }

    public function test_nothing_is_sent_while_the_setting_is_off_which_is_the_default(): void
    {
        Process::fake();

        $this->makeRun()->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        Process::assertNothingRan();
    }

    public function test_only_a_change_to_needs_you_sends_one(): void
    {
        Process::fake();
        Setting::put('native_notifications', 1);

        $this->makeRun()->moveTo(RunStatus::Failed);
        $this->makeRun(RunStatus::NeedsHuman)->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        Process::assertNothingRan();
    }

    public function test_agent_text_is_passed_as_one_literal_argument_after_the_end_of_options(): void
    {
        Process::fake();
        Setting::put('native_notifications', 1);
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
        Process::fake();
        Setting::put('native_notifications', 1);
        $run = $this->makeRun();
        $run->messages()->createMany([
            ['role' => 'agent', 'body' => 'Should the export include archived repositories, or only the active ones that still have runs this month?'],
            ['role' => 'agent', 'body' => 'Second question'],
        ]);

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'question']);

        $this->assertSame(
            ['Foreman: app #42', 'Question: Should the export include archived repositories, or only the active ones that still have...'],
            $this->notifiedWith(),
        );
    }

    public function test_a_question_on_several_lines_is_shown_on_one(): void
    {
        Process::fake();
        Setting::put('native_notifications', 1);
        $run = $this->makeRun();
        $run->messages()->create(['role' => 'agent', 'body' => "\n## Scope\n\nShould archived repositories be included?"]);

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'question']);

        $this->assertSame(['Foreman: app #42', 'Question: ## Scope Should archived repositories be included?'], $this->notifiedWith());
    }

    public function test_a_failed_notification_is_not_shown_as_a_run_error(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);
        Setting::put('native_notifications', 1);
        $run = $this->makeRun();

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame('message', collect($run->logEntries())->last()['kind']);
    }

    public function test_a_failed_notification_is_logged_and_the_move_still_happens(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Not authorised to send Apple events', exitCode: 1)]);
        Setting::put('native_notifications', 1);
        $run = $this->makeRun();

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame(RunStatus::NeedsHuman, $run->fresh()->status);
        $this->assertStringContainsString('macOS notification not sent: Not authorised to send Apple events', $run->tail());
    }

    public function test_a_notification_that_throws_is_logged_and_does_not_fail_the_run(): void
    {
        Process::fake(fn () => throw new \RuntimeException('The process exceeded the timeout of 5 seconds.'));
        Setting::put('native_notifications', 1);
        $run = $this->makeRun();

        $run->moveTo(RunStatus::NeedsHuman, ['stop_reason' => 'gate_failed']);

        $this->assertSame(RunStatus::NeedsHuman, $run->fresh()->status);
        $this->assertStringContainsString('macOS notification not sent: The process exceeded the timeout of 5 seconds.', $run->tail());
    }
}
