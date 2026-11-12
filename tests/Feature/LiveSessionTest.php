<?php

namespace Tests\Feature;

use App\Services\Claude\ClaudeCli;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\ClaudeCliHarness;
use Tests\TestCase;

/** A run is a live session: the operator's messages go in while it works, and a question waits for an answer. See ADR 0009. */
class LiveSessionTest extends TestCase
{
    use ClaudeCliHarness, RefreshDatabase;

    /**
     * Fakes the agent as a live session: one line of `$lines` per tick of Foreman's loop, then still running for
     * `$idle` more ticks, as the real CLI does until its stdin closes. Sleeps are faked and move the clock.
     */
    private function fakeLiveClaude(array $lines, int $idle = 5): void
    {
        Sleep::fake(syncWithCarbon: true);

        $description = Process::describe();
        foreach ($lines as $line) {
            $description->output($line);
        }

        Process::fake(function (PendingProcess $process) use ($description, $lines, $idle) {
            $this->started[] = $process;

            return $description->iterations(count($lines) + $idle);
        });
    }

    public function test_a_turn_that_ends_with_nothing_from_the_operator_closes_stdin_so_the_session_ends(): void
    {
        $this->fakeLiveClaude([self::RESULT]);

        $agent = (new ClaudeCli)->agent($this->makeRun(), $this->worktree, 'Do it');

        $this->assertTrue($this->started[0]->input->isClosed());
        $this->assertSame('sess-9', $agent->sessionId);
    }

    public function test_an_operators_message_goes_into_the_live_session_and_is_logged_when_it_arrives(): void
    {
        $run = $this->makeRun();
        $message = $run->messages()->create(['role' => 'human', 'body' => 'Stop, that is the wrong file.']);
        $sent = "Your operator sent this from Foreman:\n\nStop, that is the wrong file.";
        $this->fakeLiveClaude([
            '{"type":"assistant","message":{"content":[{"type":"text","text":"Editing app.php"}]}}',
            '{"type":"user","isReplay":true,"message":{"role":"user","content":"Do it"}}',
            json_encode(['type' => 'user', 'isReplay' => true, 'message' => ['role' => 'user', 'content' => $sent]]),
            self::RESULT,
        ]);

        (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertSame(['Do it', $sent], array_column(array_column($this->sentMessages(), 'message'), 'content'));
        $this->assertNotNull($message->fresh()->sent_at);
        $log = File::get($run->logPath());
        $this->assertStringContainsString('You: Stop, that is the wrong file.', $log);
        $this->assertStringNotContainsString('You: Do it', $log);
    }

    /** The agent's turn ends on a `question` result file. */
    private function agentAsks(string ...$questions): void
    {
        File::ensureDirectoryExists("{$this->worktree}/.foreman");
        File::put("{$this->worktree}/.foreman/result.json", json_encode(['outcome' => 'question', 'questions' => $questions]));
    }

    public function test_an_answer_inside_the_question_window_goes_into_the_same_session(): void
    {
        $run = $this->makeRun(['question_window_minutes' => 10]);
        $this->agentAsks('Include archived rows?');
        $this->fakeLiveClaude([self::RESULT], idle: 20);

        // The operator answers a few ticks after the question shows up
        $waited = 0;
        Sleep::whenFakingSleep(function () use ($run, &$waited) {
            if ($run->fresh()->question_until && ++$waited === 3) {
                $run->messages()->create(['role' => 'human', 'body' => 'No, only active rows.']);
            }
        });

        (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertCount(1, $this->started);
        // Still open for the agent's next turn
        $this->assertFalse($this->started[0]->input->isClosed());
        $answer = $this->sentMessages()[1]['message']['content'];
        $this->assertStringContainsString('No, only active rows.', $answer);
        $this->assertStringContainsString('.foreman/result.json', $answer);
        $this->assertSame([['agent', 'Include archived rows?'], ['human', 'No, only active rows.']], $run->messages->map(fn ($m) => [$m->role, $m->body])->all());
        $this->assertNull($run->fresh()->question_until);
        // The agent writes a fresh result for the turn the answer starts
        $this->assertFileDoesNotExist("{$this->worktree}/.foreman/result.json");
        $this->assertStringContainsString('Agent asked 1 question. Waiting up to 10 min for your answer.', File::get($run->logPath()));
    }

    public function test_with_no_answer_in_the_question_window_the_session_ends_on_the_question(): void
    {
        $run = $this->makeRun(['question_window_minutes' => 1]);
        $this->agentAsks('Include archived rows?');
        // 2 seconds a tick, so the minute runs out well before the fake process stops
        $this->fakeLiveClaude([self::RESULT], idle: 40);

        $agent = (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertTrue($this->started[0]->input->isClosed());
        $this->assertCount(1, $this->sentMessages());
        $this->assertNull($run->fresh()->question_until);
        // The result file is left for Foreman's usual question flow
        $this->assertFileExists("{$this->worktree}/.foreman/result.json");
        $this->assertSame('sess-9', $agent->sessionId);
        $this->assertStringContainsString('No answer within 1 min. Ending the session on the question.', File::get($run->logPath()));
    }

    public function test_a_question_with_the_window_off_ends_the_session_at_once(): void
    {
        $run = $this->makeRun(['question_window_minutes' => 0]);
        $this->agentAsks('Include archived rows?');
        $this->fakeLiveClaude([self::RESULT]);

        (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertTrue($this->started[0]->input->isClosed());
        $this->assertSame(0, $run->messages()->count());
    }

    public function test_a_session_of_several_turns_reports_its_whole_usage(): void
    {
        $run = $this->makeRun();
        $run->messages()->create(['role' => 'human', 'body' => 'Also update the docs.']);
        // The CLI reports the session's total cost, but the turns and tokens of the last turn alone
        $this->fakeLiveClaude([
            '{"type":"assistant","message":{"content":[{"type":"text","text":"Working"}]}}',
            self::RESULT,
            '{"type":"result","num_turns":3,"total_cost_usd":0.8,"session_id":"sess-9","usage":{"input_tokens":1,"output_tokens":2,"cache_read_input_tokens":3,"cache_creation_input_tokens":4}}',
        ]);

        $agent = (new ClaudeCli)->agent($run, $this->worktree, 'Do it');

        $this->assertSame(['cost_usd' => 0.8, 'num_turns' => 5, 'input_tokens' => 11, 'output_tokens' => 22, 'cache_read_tokens' => 33, 'cache_write_tokens' => 44], $agent->usage());
    }
}
