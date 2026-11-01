<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Repository extends Model
{
    public const DEFAULT_ALLOWED_TOOLS = 'Read,Edit,Write,Glob,Grep,Skill,Agent,Bash(git:*),Bash(composer:*),Bash(php artisan:*),Bash(./vendor/bin/*),Bash(npm:*),Bash(npx:*),Bash(pnpm:*),Bash(yarn:*),Bash(gh issue:*),Bash(gh pr create:*)';

    public const DEFAULT_SETUP_SCRIPT = <<<'SH'
cp .env.example .env
composer install --no-interaction --quiet
php artisan key:generate --quiet
npm ci --silent
npm run build --silent
SH;

    /** Model aliases the CLI accepts, for the settings form and the dashboard popover. */
    public const MODELS = ['haiku' => 'Haiku', 'sonnet' => 'Sonnet', 'opus' => 'Opus', 'fable' => 'Fable'];

    /** Effort levels for `--effort`. No effort at all (null) is the "Default" choice. */
    public const EFFORTS = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'XHigh', 'max' => 'Max'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cleanup_worktrees' => 'boolean',
            'watch_prs' => 'boolean',
            'daily_budget' => 'decimal:2',
            'last_polled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Runs in progress or PRs still being watched, which keep it from being archived. */
    public function hasWorkInFlight(): bool
    {
        return $this->runs()
            ->whereIn('status', [...RunStatus::activeValues(), RunStatus::WatchingPr->value])
            ->exists();
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

        return storage_path("foreman/worktrees/{$slug}/issue-{$issue}");
    }

    /** Model and effort as the dashboard shows them: "sonnet", or "sonnet high" when effort is set. */
    public function modelLabel(): string
    {
        return trim("{$this->model} {$this->effort}");
    }

    /** @return list<string> The globs from the "Locked paths" setting, one per line. */
    public function lockedPathList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->locked_paths))));
    }

    /** Name used for per-profile state like usage-limit pauses. */
    public function profileName(): string
    {
        return $this->claude_profile ?: 'default';
    }

    /** Environment that points Claude at this repository's profile. */
    public function claudeEnv(): array
    {
        if (! $this->claude_profile) {
            return [];
        }

        $dir = config("foreman.claude_profiles.{$this->claude_profile}")
            ?? throw new RuntimeException("Claude profile “{$this->claude_profile}” isn’t in CLAUDE_PROFILES.");

        // Setting CLAUDE_CONFIG_DIR at all, even to ~/.claude, makes Claude look for a separate Keychain login
        if (rtrim($dir, '/') === rtrim((string) getenv('HOME'), '/').'/.claude') {
            return [];
        }

        return ['CLAUDE_CONFIG_DIR' => $dir];
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
