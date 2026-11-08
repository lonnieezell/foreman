<?php

namespace App\Livewire;

use App\Enums\Difficulty;
use App\Models\Repository;
use App\Models\Setting;
use App\Support\Ladder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    // Form field => [setting key, multiplier from stored value to what's shown]. Thresholds are stored as fractions but entered as percentages.
    private const FIELDS = [
        'five_hour_ceiling' => ['usage.five_hour_ceiling', 100],
        'weekly_pace_margin' => ['usage.weekly_pace_margin', 100],
        'weekly_cap' => ['usage.weekly_cap', 100],
        'probe_minutes' => ['usage.probe_minutes', 1],
        'pr_max_rounds' => ['pr_watch.max_rounds', 1],
        'pr_quiet_minutes' => ['pr_watch.quiet_minutes', 1],
        'pr_timeout_minutes' => ['pr_watch.timeout_minutes', 1],
    ];

    // Empty means use the default from .env or config/foreman.php
    public string $five_hour_ceiling = '';

    public string $weekly_pace_margin = '';

    public string $weekly_cap = '';

    public string $probe_minutes = '';

    public string $pr_max_rounds = '';

    public string $pr_quiet_minutes = '';

    public string $pr_timeout_minutes = '';

    // Empty means no limit
    public string $global_daily_budget = '';

    public bool $trust_bot_labels = false;

    // Saved as soon as it's toggled, since the browser asks for permission at the same moment
    public bool $desktop_notifications = false;

    public bool $native_notifications = false;

    /** @var array<string,array{model:string,effort:string}> The step for each difficulty, by its value. */
    public array $ladder = [];

    /** @var array{model:string,effort:string} */
    public array $fallback = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->desktop_notifications = Setting::desktopNotifications();

        foreach (self::FIELDS as $field => [$key, $scale]) {
            $value = Setting::get($key);
            $this->{$field} = $value === null ? '' : (string) round($value * $scale, 2);
        }

        $this->global_daily_budget = (string) (Setting::globalBudget() ?? '');
        $this->trust_bot_labels = Setting::trustBotLabels();
        $this->native_notifications = Setting::nativeNotifications();
        $this->ladder = Ladder::steps();
        $this->fallback = Ladder::fallback();
    }

    public function updatedDesktopNotifications(): void
    {
        Setting::put('desktop_notifications', $this->desktop_notifications ? 1 : 0);
    }

    public function save(): void
    {
        $this->validate([
            'five_hour_ceiling' => ['nullable', 'numeric', 'between:1,100'],
            'weekly_pace_margin' => ['nullable', 'numeric', 'between:0,100'],
            'weekly_cap' => ['nullable', 'numeric', 'between:1,100'],
            'probe_minutes' => ['nullable', 'integer', 'min:1'],
            'pr_max_rounds' => ['nullable', 'integer', 'between:0,10'],
            'pr_quiet_minutes' => ['nullable', 'integer', 'min:1'],
            'pr_timeout_minutes' => ['nullable', 'integer', 'min:1'],
            'global_daily_budget' => ['nullable', 'numeric', 'min:0'],
            ...collect(Difficulty::values())->flatMap(fn ($d) => [
                "ladder.{$d}.model" => ['required', Rule::in(array_keys(Ladder::models()))],
                "ladder.{$d}.effort" => ['required', Rule::in(array_keys(Repository::EFFORTS))],
            ])->all(),
            'fallback.model' => ['required', Rule::in(array_keys(Ladder::models()))],
            'fallback.effort' => ['required', Rule::in(array_keys(Repository::EFFORTS))],
        ]);

        if ($problem = Ladder::orderProblem($this->ladder)) {
            $this->addError('ladder', $problem);

            return;
        }

        foreach (self::FIELDS as $field => [$key, $scale]) {
            $this->{$field} === ''
                ? Setting::whereKey($key)->delete()
                : Setting::put($key, $this->{$field} / $scale);
        }

        Setting::put('global_daily_budget', $this->global_daily_budget === '' ? null : $this->global_daily_budget);
        Setting::put('trust_bot_labels', $this->trust_bot_labels ? 1 : 0);
        Setting::put('native_notifications', $this->native_notifications ? 1 : 0);
        // Null while they match the defaults, so later changes to the defaults reach this install
        Setting::put('ladder', $this->ladder == Ladder::DEFAULT ? null : json_encode($this->ladder));
        Setting::put('fallback_step', $this->fallback == Ladder::DEFAULT_FALLBACK ? null : json_encode($this->fallback));
        Setting::applyToConfig();

        $this->flash = 'Settings saved.';
    }

    public function render()
    {
        $defaults = config('foreman.defaults');

        return view('livewire.settings', [
            // Placeholders showing what an empty field falls back to
            'defaults' => collect(self::FIELDS)->map(fn ($f) => (string) round($defaults[$f[0]] * $f[1], 2))->all(),
        ]);
    }
}
