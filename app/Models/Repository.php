<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    public const DEFAULT_ALLOWED_TOOLS = 'Read,Edit,Write,Glob,Grep,Skill,Agent,Bash(git:*),Bash(composer:*),Bash(php artisan:*),Bash(./vendor/bin/*),Bash(npm:*),Bash(npx:*),Bash(pnpm:*),Bash(yarn:*),Bash(gh issue:*),Bash(gh pr create:*)';

    public const DEFAULT_SETUP_SCRIPT = <<<'SH'
cp .env.example .env
composer install --no-interaction --quiet
php artisan key:generate --quiet
SH;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cleanup_worktrees' => 'boolean',
            'daily_budget' => 'decimal:2',
            'last_polled_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function activeRuns(): HasMany
    {
        return $this->runs()->active();
    }

    public function spentToday(): float
    {
        return (float) $this->runs()->where('created_at', '>=', now()->startOfDay())->sum('cost_usd');
    }

    public function budgetReachedToday(): bool
    {
        return $this->daily_budget !== null && $this->spentToday() >= (float) $this->daily_budget;
    }

    public function dueForPoll(): bool
    {
        return $this->last_polled_at === null
            || $this->last_polled_at->addMinutes($this->poll_interval_minutes)->isPast();
    }

    public function freeSlots(): int
    {
        return max(0, $this->concurrency - $this->activeRuns()->count());
    }

    public function worktreePath(int $issue): string
    {
        $slug = str_replace('/', '-', $this->github_repo);

        return storage_path("factory/worktrees/{$slug}/issue-{$issue}");
    }

    public function renderPrompt(Run $run): string
    {
        $template = $this->prompt_template
            ?: file_get_contents(resource_path('prompts/default.md'));

        return strtr($template, [
            '{{issue}}' => (string) $run->issue_number,
            '{{branch}}' => $run->branch,
            '{{base}}' => $this->base_branch,
            '{{repo}}' => $this->github_repo,
            '{{human_label}}' => $this->human_label,
        ]);
    }
}
