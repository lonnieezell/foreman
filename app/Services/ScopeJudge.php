<?php

namespace App\Services;

use App\Models\Repository;
use App\Services\Claude\Claude;
use App\Support\IssueSanitiser;
use App\Support\Prompt;
use Closure;
use Throwable;

/**
 * A separate Haiku call with no tools that decides whether a change stays within the issue and leaves the tests alone.
 * It sees only the cleaned-up issue and the diff, never the agent's session or reasoning.
 */
final class ScopeJudge
{
    /** The judge's answer. */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'adds_unrequested' => ['type' => 'boolean'],
            'misses_requested' => ['type' => 'boolean'],
            'weakens_tests' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['adds_unrequested', 'misses_requested', 'weakens_tests', 'reason'],
    ];

    /** @param  Closure(string):void  $progress  Gets each step's message. The caller logs it, checks for cancellation and keeps the heartbeat fresh. */
    public function __construct(private Repository $repo, private Closure $progress) {}

    /**
     * An unusable answer is asked again once. A second one gives null.
     * `$reply` is the agent's answer to an earlier veto. Its evidence is read from `$worktree`'s base branch.
     */
    public function verdict(string $issue, string $diff, ?JudgeReply $reply = null, ?Worktree $worktree = null): ?ScopeVerdict
    {
        $prompt = Prompt::render('scope-judge', [
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'issue' => $issue,
            'diff' => IssueSanitiser::wrapText($diff, clean: false),
            'answer' => $reply === null ? '' : Prompt::render('scope-judge-answer', [
                'claim' => IssueSanitiser::wrapText($reply->claim),
                'excerpts' => self::excerpts($worktree === null ? [] : $reply->excerpts($worktree)),
            ]),
        ]);

        foreach ([1, 2] as $attempt) {
            ($this->progress)('Scope judge: asking Haiku whether the change stays within the issue'.($attempt > 1 ? ', again.' : '.'));

            try {
                $answer = app(Claude::class)->oneShot($this->repo, $prompt, self::SCHEMA);
                $verdict = $answer->structured;

                if (is_array($verdict) && is_bool($verdict['adds_unrequested'] ?? null) && is_bool($verdict['misses_requested'] ?? null)
                    && is_bool($verdict['weakens_tests'] ?? null) && is_string($verdict['reason'] ?? null) && trim($verdict['reason']) !== '') {
                    // Model output shaped by untrusted text, bound for a markdown list and a quote
                    return new ScopeVerdict($verdict['adds_unrequested'], $verdict['misses_requested'], $verdict['weakens_tests'],
                        (string) str($verdict['reason'])->squish()->limit(500));
                }

                $problem = $answer->error ?? 'an answer without adds_unrequested, misses_requested, weakens_tests and reason: '.str($answer->output)->limit(500);
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }

            ($this->progress)("Scope judge gave no usable answer ({$problem}).");
        }

        return null;
    }

    /** @param  list<array{path:string,start:int,end:int,code:?string}>  $excerpts */
    private static function excerpts(array $excerpts): string
    {
        return collect($excerpts)
            ->map(fn ($e) => "### {$e['path']}, lines {$e['start']}-{$e['end']}\n\n"
                .($e['code'] === null ? 'Not on the base branch.' : IssueSanitiser::wrapText(str($e['code'])->limit(8000), clean: false)))
            ->implode("\n\n") ?: 'The author pointed to no code.';
    }
}
