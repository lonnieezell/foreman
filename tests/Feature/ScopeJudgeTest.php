<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\Claude\OneShotResult;
use App\Services\ScopeJudge;
use App\Services\ScopeVerdict;
use RuntimeException;
use Tests\Support\FakeClaude;
use Tests\TestCase;

class ScopeJudgeTest extends TestCase
{
    private const DIFF = "diff --git a/app/A.php b/app/A.php\n+return 1;\n";

    private FakeClaude $claude;

    /** @var list<string> */
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->claude = FakeClaude::install($this->app);
    }

    /** @param  list<array|string|null|RuntimeException>  $answers  A verdict, raw output (string), a failed call (null) or a thrown error, in order. */
    private function judge(array $answers): ?ScopeVerdict
    {
        $this->claude->onOneShot = function () use (&$answers) {
            $answer = array_shift($answers);

            return match (true) {
                $answer instanceof RuntimeException => throw $answer,
                $answer === null => new OneShotResult(error: 'exit 1: API Error: overloaded'),
                is_string($answer) => new OneShotResult(output: $answer),
                default => $answer,
            };
        };

        $repo = new Repository(['name' => 'app']);

        return (new ScopeJudge($repo, fn (string $message) => $this->progress[] = $message))
            ->verdict('<untrusted_issue>Fix the bug</untrusted_issue>', self::DIFF);
    }

    public function test_it_asks_one_call_that_sees_the_issue_and_the_diff(): void
    {
        $this->judge([['in_scope' => true, 'weakens_tests' => false, 'reason' => 'Fine.']]);

        $this->assertCount(1, $this->claude->oneShotCalls);
        $call = $this->claude->oneShotCalls[0];
        $this->assertSame('app', $call['repo']->name);
        $this->assertSame(['in_scope', 'weakens_tests', 'reason'], $call['schema']['required']);
        $this->assertStringContainsString('<untrusted_issue>Fix the bug</untrusted_issue>', $call['prompt']);
        $this->assertStringContainsString('+return 1;', $call['prompt']);
        $this->assertStringNotContainsString('{{', $call['prompt']);
    }

    public function test_a_change_in_scope_that_leaves_the_tests_alone_passes(): void
    {
        $verdict = $this->judge([['in_scope' => true, 'weakens_tests' => false, 'reason' => 'Only fixes the bug.']]);

        $this->assertTrue($verdict->passes());
        $this->assertSame('Only fixes the bug.', $verdict->reason);
    }

    public function test_a_change_out_of_scope_fails(): void
    {
        $verdict = $this->judge([['in_scope' => false, 'weakens_tests' => false, 'reason' => 'Adds a settings page.']]);

        $this->assertFalse($verdict->passes());
        $this->assertSame('Out of scope: Adds a settings page.', $verdict->describe());
    }

    public function test_weakened_tests_fail_even_in_scope(): void
    {
        $verdict = $this->judge([['in_scope' => true, 'weakens_tests' => true, 'reason' => 'Skips ATest.']]);

        $this->assertFalse($verdict->passes());
        $this->assertSame('Weakens tests: Skips ATest.', $verdict->describe());
    }

    public function test_both_problems_are_named(): void
    {
        $verdict = $this->judge([['in_scope' => false, 'weakens_tests' => true, 'reason' => 'Both.']]);

        $this->assertSame('Out of scope, weakens tests: Both.', $verdict->describe());
    }

    public function test_an_unusable_answer_is_asked_again_once(): void
    {
        $verdict = $this->judge([null, ['in_scope' => true, 'weakens_tests' => false, 'reason' => 'Fine.']]);

        $this->assertCount(2, $this->claude->oneShotCalls);
        $this->assertTrue($verdict->passes());
        $this->assertSame([
            'Scope judge: asking Haiku whether the change stays within the issue.',
            'Scope judge gave no usable answer (exit 1: API Error: overloaded).',
            'Scope judge: asking Haiku whether the change stays within the issue, again.',
        ], $this->progress);
    }

    public function test_two_unusable_answers_give_no_verdict(): void
    {
        $verdict = $this->judge(['not json', ['in_scope' => true, 'reason' => 'No weakens_tests.']]);

        $this->assertNull($verdict);
        $this->assertCount(2, $this->claude->oneShotCalls);
        $this->assertStringContainsString('an answer without in_scope, weakens_tests and reason: not json', $this->progress[1]);
    }

    public function test_a_blank_reason_is_unusable(): void
    {
        $this->assertNull($this->judge([
            ['in_scope' => true, 'weakens_tests' => false, 'reason' => '  '],
            ['in_scope' => true, 'weakens_tests' => false, 'reason' => ''],
        ]));
    }

    public function test_a_call_that_throws_counts_as_unusable(): void
    {
        $verdict = $this->judge([new RuntimeException('timed out'), ['in_scope' => true, 'weakens_tests' => false, 'reason' => 'Fine.']]);

        $this->assertTrue($verdict->passes());
        $this->assertSame('Scope judge gave no usable answer (timed out).', $this->progress[1]);
    }

    public function test_a_multi_line_reason_is_kept_on_one_line(): void
    {
        $verdict = $this->judge([['in_scope' => false, 'weakens_tests' => false, 'reason' => "Still adds\n\n# the settings page."]]);

        $this->assertSame('Out of scope: Still adds # the settings page.', $verdict->describe());
    }
}
