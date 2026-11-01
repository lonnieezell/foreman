<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_masthead_links_to_settings(): void
    {
        Process::fake();

        $this->get('/')->assertSee('href="'.route('settings').'"', false);
        $this->get('/settings')->assertOk()->assertSee('Usage holds')->assertSee('PR watching');
    }

    public function test_saved_values_override_config_and_percentages_are_stored_as_fractions(): void
    {
        Livewire::test(Settings::class)
            ->set('five_hour_ceiling', '70')
            ->set('pr_max_rounds', '0')
            ->set('global_daily_budget', '25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('flash', 'Settings saved.');

        $this->assertEquals(0.7, Setting::get('usage.five_hour_ceiling'));
        $this->assertEquals(0, Setting::get('pr_watch.max_rounds'));
        $this->assertSame(25.0, Setting::globalBudget());
        $this->assertSame(0.7, config('foreman.usage.five_hour_ceiling'));
        $this->assertSame(0, config('foreman.pr_watch.max_rounds'));
        $this->assertSame(0.9, config('foreman.usage.weekly_cap'));   // left empty, so still the default

        Livewire::test(Settings::class)->assertSet('five_hour_ceiling', '70')->assertSet('weekly_cap', '');
    }

    public function test_trusting_bot_labels_is_off_by_default_and_saved_with_the_form(): void
    {
        $this->assertFalse(Setting::trustBotLabels());

        Livewire::test(Settings::class)
            ->assertSet('trust_bot_labels', false)
            ->set('trust_bot_labels', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Setting::trustBotLabels());
        Livewire::test(Settings::class)->assertSet('trust_bot_labels', true);
    }

    public function test_clearing_a_field_goes_back_to_the_default(): void
    {
        Setting::put('usage.weekly_cap', 0.5);
        Setting::put('global_daily_budget', 10);
        Setting::applyToConfig();
        $this->assertSame(0.5, config('foreman.usage.weekly_cap'));

        Livewire::test(Settings::class)
            ->assertSet('weekly_cap', '50')
            ->set('weekly_cap', '')
            ->set('global_daily_budget', '')
            ->call('save');

        $this->assertNull(Setting::find('usage.weekly_cap'));
        $this->assertNull(Setting::globalBudget());
        $this->assertSame(0.9, config('foreman.usage.weekly_cap'));
    }

    public function test_a_worker_picks_up_values_saved_after_it_started(): void
    {
        Setting::put('pr_watch.quiet_minutes', '12');   // saved by the web process; this one still has the default

        $this->assertSame(5, config('foreman.pr_watch.quiet_minutes'));

        Setting::applyToConfig();   // what runs before each queued job

        $this->assertSame(12, config('foreman.pr_watch.quiet_minutes'));
    }

    public function test_values_outside_their_limits_are_rejected(): void
    {
        Livewire::test(Settings::class)
            ->set('five_hour_ceiling', '0')
            ->set('weekly_pace_margin', '101')
            ->set('weekly_cap', '150')
            ->set('probe_minutes', '0')
            ->set('pr_max_rounds', '11')
            ->set('pr_quiet_minutes', '1.5')
            ->set('pr_timeout_minutes', '0')
            ->set('global_daily_budget', '-1')
            ->call('save')
            ->assertHasErrors([
                'five_hour_ceiling', 'weekly_pace_margin', 'weekly_cap', 'probe_minutes',
                'pr_max_rounds', 'pr_quiet_minutes', 'pr_timeout_minutes', 'global_daily_budget',
            ]);

        $this->assertSame(0, Setting::count());
    }

    public function test_the_dashboard_shows_the_daily_limit_read_only(): void
    {
        Process::fake();

        $this->get('/')
            ->assertDontSee('id="globalBudget"', false)
            ->assertSee('no <a href="'.route('settings').'" wire:navigate>limit</a>', false);

        Setting::put('global_daily_budget', 50);

        Livewire::test(Dashboard::class)
            ->assertSee('of $50.00 <a href="'.route('settings').'" wire:navigate>limit</a>', false);
    }
}
