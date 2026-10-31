<?php

namespace Tests\Feature;

use App\Livewire\RepositoryForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupScriptPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_offers_to_copy_the_setup_script_prompt(): void
    {
        Livewire::test(RepositoryForm::class)
            ->assertSeeHtml('aria-label="Copy a prompt for Claude Code to work out this script"');
    }

    public function test_the_prompt_uses_the_unsaved_form_values(): void
    {
        $prompt = Livewire::test(RepositoryForm::class)
            ->set('base_branch', 'develop')
            ->set('setup_script', "cp .env.example .env\nmake deps")
            ->set('allowed_tools', 'Read,Bash(make:*)')
            ->instance()
            ->setupScriptPrompt();

        $this->assertStringContainsString('origin/develop', $prompt);
        $this->assertStringContainsString("cp .env.example .env\nmake deps", $prompt);
        $this->assertStringContainsString('Read,Bash(make:*)', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }
}
