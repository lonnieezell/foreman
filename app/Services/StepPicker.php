<?php

namespace App\Services;

use App\Enums\Difficulty;
use App\Guardrails\StackSet;
use App\Models\Repository;
use App\Models\Run;
use App\Services\Claude\Claude;
use App\Support\IssueSanitiser;
use App\Support\Ladder;
use App\Support\Prompt;
use Closure;
use Throwable;

/**
 * Picks the model and effort for an issue in an Auto repository: a `foreman:<difficulty>` label, else the pick of an
 * earlier run on the issue, else a Haiku classifier, else the fallback step. Every pick is clamped to the repository's
 * floor and ceiling (ADR 0004).
 */
final class StepPicker
{
    /** About how much issue text the classifier reads. */
    public const MAX_CHARS = 20000;

    /** The most of that the issue body may take. The newest comments get the rest. */
    private const MAX_BODY_CHARS = 12000;

    /** @var list<array<string,int|float|null>> What each classifier call spent, keyed by the Run's usage columns. */
    public array $usage = [];

    /** @param  Closure(string):void  $progress  Gets each step's message. */
    public function __construct(private Repository $repo, private GitHub $github, private Closure $progress) {}

    /**
     * @param  list<string>  $labels  The issue's labels.
     * @param  Run|null  $earlier  An earlier run on the issue whose pick is kept, unless a label overrides it.
     */
    public function pick(int $issue, array $labels, ?Run $earlier = null): Pick
    {
        $label = Difficulty::fromLabels($labels);

        $pick = match (true) {
            $label !== null => new Pick($label, 'label', "The issue has the {$label->label()} label.", ...Ladder::step($label)),
            $earlier !== null => new Pick(Difficulty::tryFrom((string) $earlier->difficulty), $earlier->difficulty_source,
                "Kept from run {$earlier->id}.", $earlier->model, $earlier->effort),
            default => $this->classify($issue)
                ?? new Pick(null, 'fallback', 'The classifier gave no usable answer twice, so this is the fallback step.', ...Ladder::fallback()),
        };

        return $this->clamp($pick);
    }

    /** Asks Haiku how hard the issue is. An unusable answer is asked again once. A second one gives null. */
    private function classify(int $issue): ?Pick
    {
        $text = $this->github->sanitisedIssue($this->repo, $issue, maxBody: self::MAX_BODY_CHARS);
        $comments = $this->github->trustedComments($this->repo, $issue, null, maxChars: max(0, self::MAX_CHARS - mb_strlen($text)));
        $stacks = is_dir($this->repo->local_path) ? StackSet::detect($this->repo->local_path) : new StackSet([]);

        $prompt = Prompt::render('difficulty', [
            'untrusted_note' => IssueSanitiser::TEXT_NOTE,
            'stacks' => implode(', ', $stacks->names()) ?: 'None detected.',
            'tier3_paths' => collect($stacks->rules('tier3Paths'))->map(fn ($p) => "- `{$p}`")->implode("\n") ?: 'None.',
            'issue' => $text,
            'comments' => $comments ?: 'None.',
        ]);

        foreach ([1, 2] as $attempt) {
            ($this->progress)('Classifier: asking Haiku how hard the issue is'.($attempt > 1 ? ', again.' : '.'));

            try {
                $answer = app(Claude::class)->oneShot($this->repo, $prompt, [
                    'type' => 'object',
                    'properties' => [
                        'difficulty' => ['type' => 'string', 'enum' => Difficulty::values()],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['difficulty', 'reason'],
                ]);

                $this->usage[] = $answer->usage;

                $difficulty = Difficulty::tryFrom((string) ($answer->structured['difficulty'] ?? ''));
                $reason = $answer->structured['reason'] ?? null;

                if ($difficulty !== null && is_string($reason) && trim($reason) !== '') {
                    // Model output shaped by untrusted text, bound for the log
                    return new Pick($difficulty, 'classifier', (string) str($reason)->squish()->limit(300), ...Ladder::step($difficulty));
                }

                $problem = $answer->error ?? 'an answer without a difficulty and a reason: '.str($answer->output)->limit(500);
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }

            ($this->progress)("Classifier gave no usable answer ({$problem}).");
        }

        return null;
    }

    /** Keeps the pick within the repository's floor and ceiling. A pick without a difficulty is compared by its step. */
    private function clamp(Pick $pick): Pick
    {
        $floor = Difficulty::from($this->repo->difficulty_floor);
        $ceiling = Difficulty::from($this->repo->difficulty_ceiling);
        $rank = fn (Difficulty $d) => $pick->difficulty === null ? Ladder::rank(Ladder::step($d)) : $d->rank();
        $own = $pick->difficulty?->rank() ?? Ladder::rank(['model' => $pick->model, 'effort' => $pick->effort]);

        $to = match (true) {
            $own < $rank($floor) => $floor,
            $own > $rank($ceiling) => $ceiling,
            default => null,
        };

        if ($to === null) {
            return $pick;
        }

        $was = $pick->difficulty === null ? "{$pick->model} {$pick->effort}" : $pick->difficulty->value;
        $bound = $to === $floor ? 'floor' : 'ceiling';

        return new Pick($to, $pick->source, $pick->reason, ...Ladder::step($to),
            clamped: "Clamped to the repository's {$bound}, {$to->value}, from {$was}.");
    }
}
