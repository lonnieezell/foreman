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
            'in_scope' => ['type' => 'boolean'],
            'weakens_tests' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
        ],
        'required' => ['in_scope', 'weakens_tests', 'reason'],
    ];

    /** @param  Closure(string):void  $progress  Gets each step's message. The caller logs it, checks for cancellation and keeps the heartbeat fresh. */
    public function __construct(private Repository $repo, private Closure $progress) {}

    /** An unusable answer is asked again once. A second one gives null. */
    public function verdict(string $issue, string $diff): ?ScopeVerdict
    {
        $prompt = Prompt::render('scope-judge', [
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'issue' => $issue,
            'diff' => IssueSanitiser::wrapText($diff, clean: false),
        ]);

        foreach ([1, 2] as $attempt) {
            ($this->progress)('Scope judge: asking Haiku whether the change stays within the issue'.($attempt > 1 ? ', again.' : '.'));

            try {
                $answer = app(Claude::class)->oneShot($this->repo, $prompt, self::SCHEMA);
                $verdict = $answer->structured;

                if (is_array($verdict) && is_bool($verdict['in_scope'] ?? null) && is_bool($verdict['weakens_tests'] ?? null)
                    && is_string($verdict['reason'] ?? null) && trim($verdict['reason']) !== '') {
                    // Model output shaped by untrusted text, bound for a markdown list and a quote
                    return new ScopeVerdict($verdict['in_scope'], $verdict['weakens_tests'], (string) str($verdict['reason'])->squish()->limit(500));
                }

                $problem = $answer->error ?? 'an answer without in_scope, weakens_tests and reason: '.str($answer->output)->limit(500);
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }

            ($this->progress)("Scope judge gave no usable answer ({$problem}).");
        }

        return null;
    }
}
