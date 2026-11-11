<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\RunScorer;
use Illuminate\Console\Command;

class Improve extends Command
{
    /** Runs that ended longer ago than this are never scored, so the first pass backfills only this far (ADR 0008). */
    public const BACKFILL_DAYS = 30;

    protected $signature = 'foreman:improve';

    protected $description = 'Score every ended run that has no scorecard yet, from its logs. Spends no tokens.';

    public function handle(RunScorer $scorer): int
    {
        // Still on the line or watching its PR isn't ended. A cancelled run is never scored.
        $notEnded = [...RunStatus::activeValues(), RunStatus::WatchingPr->value, RunStatus::Cancelled->value];
        $scored = 0;

        Run::query()
            ->whereNotIn('status', $notEnded)
            ->whereDoesntHave('scorecard')
            ->whereRaw('coalesce(finished_at, updated_at) >= ?', [now()->subDays(self::BACKFILL_DAYS)])
            ->orderBy('id')
            ->each(function (Run $run) use ($scorer, &$scored) {
                $scorer->score($run);
                $scored++;
            });

        $this->line("Scored {$scored} ".str('run')->plural($scored).'.');

        return self::SUCCESS;
    }
}
