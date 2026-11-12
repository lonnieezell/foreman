<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Guardrails\StackSet;
use App\Support\Prompt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Repository extends Model
{
    /** The agent may inspect and commit but not push, and it has no `gh`: Foreman acts on GitHub. */
    public const DEFAULT_ALLOWED_TOOLS = 'Read,Edit,Write,Glob,Grep,Skill,Agent,Bash(git status:*),Bash(git diff:*),Bash(git log:*),Bash(git show:*),Bash(git add:*),Bash(git commit:*),Bash(git checkout:*),Bash(git restore:*),Bash(git rm:*),Bash(git mv:*),Bash(git stash:*),Bash(git clean:*),Bash(git fetch:*),Bash(git check-ignore:*),Bash(git grep:*),Bash(cp:*),Bash(mv:*),Bash(mkdir:*),Bash(rm:*),Bash(sed:*),Bash(grep:*),Bash(ls:*),Bash(cat:*),Bash(head:*),Bash(tail:*),Bash(wc:*),Bash(find:*),Bash(echo:*),Bash(composer:*),Bash(php:*),Bash(./vendor/bin/*),Bash(vendor/bin/*),Bash(npm:*),Bash(npx:*),Bash(pnpm:*),Bash(yarn:*),Bash(cargo:*),Bash(rustfmt:*),Bash(python:*),Bash(python3:*),Bash(pytest:*),Bash(ruff:*),Bash(mypy:*),Bash(pyright:*),Bash(uv:*),Bash(poetry:*)';

    /** Entries from the old default that let the agent push or act on GitHub itself. */
    public const LEGACY_ALLOWED_TOOLS = ['Bash(git:*)', 'Bash(gh issue:*)', 'Bash(gh pr create:*)'];

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

    /** `gh pr merge` flags for the auto-merge "Merge method" setting. */
    public const MERGE_METHODS = ['merge' => 'Merge', 'squash' => 'Squash', 'rebase' => 'Rebase'];

    protected $guarded = ['id'];

    // Match the column defaults, so a repository made in code is unsandboxed and merges with "merge" before it's saved
    protected $attributes = ['sandbox' => false, 'stop_docker' => false, 'merge_method' => 'merge',
        'model_mode' => 'fixed', 'difficulty_floor' => 'trivial', 'difficulty_ceiling' => 'hard', 'question_window_minutes' => 10, 'permission_timeout_minutes' => 3];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cleanup_worktrees' => 'boolean',
            'watch_prs' => 'boolean',
            'auto_merge_max_tier' => 'integer',
            'sandbox' => 'boolean',
            'stop_docker' => 'boolean',
            'daily_budget' => 'decimal:2',
            'max_budget_per_run' => 'decimal:2',
            'last_polled_at' => 'datetime',
            'intake_skips' => 'array',
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

    /** Whether Foreman picks the model and effort per issue, from the ladder (ADR 0004). */
    public function isAuto(): bool
    {
        return $this->model_mode === 'auto';
    }

    /**
     * Model and effort as the dashboard shows them: "sonnet", or "sonnet high" when effort is set.
     * In Auto mode, the difficulty range: "auto trivial–hard".
     */
    public function modelLabel(): string
    {
        return $this->isAuto() ? "auto {$this->difficulty_floor}–{$this->difficulty_ceiling}" : trim("{$this->model} {$this->effort}");
    }

    /** Whether $label is among $names. GitHub ignores case in label names, so this does too. */
    public static function hasLabel(array $names, string $label): bool
    {
        return in_array(mb_strtolower($label), array_map(mb_strtolower(...), $names), true);
    }

    /** One of the labels Foreman moves issues through, which can't be the focus label. */
    public function isWorkflowLabel(string $label): bool
    {
        return self::hasLabel([$this->ready_label, $this->working_label, $this->human_label], $label);
    }

    /** @return list<string> The globs from the "Locked paths" setting, one per line. */
    public function lockedPathList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->locked_paths))));
    }

    /** One line per verify command of the stacks found in `$folder`. Format checks cover files changed since `$base`. */
    public static function defaultVerifyScript(string $folder, string $base): string
    {
        return is_dir($folder) ? implode("\n", StackSet::detect($folder)->verifyCommands($base)) : '';
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

    /** True when the saved tool list still has entries from the old default. */
    public function hasLegacyAllowedTools(): bool
    {
        $saved = array_map('trim', explode(',', (string) $this->allowed_tools));

        return array_intersect(self::LEGACY_ALLOWED_TOOLS, $saved) !== [];
    }

    /** True when the saved tool list lets the agent commit, which every run needs before Foreman can push. */
    public function allowsCommit(): bool
    {
        $saved = array_map('trim', explode(',', (string) $this->allowed_tools));

        return array_intersect(['Bash', 'Bash(git:*)'], $saved) !== []
            || array_diff(['Bash(git add:*)', 'Bash(git commit:*)'], $saved) === [];
    }

    /** Add `$pattern` to the allowed tools, unless it is there already (ADR 0010). */
    public function allowTool(string $pattern): void
    {
        if (! in_array($pattern, array_map('trim', explode(',', (string) $this->allowed_tools)), true)) {
            $this->update(['allowed_tools' => rtrim((string) $this->allowed_tools, ', ').",{$pattern}"]);
        }
    }

    public function renderPrompt(Run $run, string $issueText): string
    {
        return Prompt::fill($this->prompt_template ?: Prompt::template('default'), [
            'issue' => (string) $run->issue_number,
            'issue_text' => $issueText,
            'branch' => $run->branch,
            'base' => $this->base_branch,
            'repo' => $this->github_repo,
            'human_label' => $this->human_label,
        ]);
    }
}
