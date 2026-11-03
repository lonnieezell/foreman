<?php

namespace Tests\Unit;

use App\Support\Prompt;
use Tests\TestCase;

class PromptTest extends TestCase
{
    public function test_it_fills_a_template_from_resources_prompts(): void
    {
        $prompt = Prompt::render('verify-script', ['base' => 'develop', 'verify_script' => 'composer test']);

        $this->assertStringContainsString('composer test', $prompt);
        $this->assertStringContainsString('develop', $prompt);
        $this->assertStringNotContainsString('{{base}}', $prompt);
        $this->assertStringNotContainsString('{{verify_script}}', $prompt);
    }

    public function test_it_fills_a_template_given_as_text(): void
    {
        $this->assertSame('Fix #7 on agent/issue-7, not {{other}}.', Prompt::fill('Fix #{{issue}} on {{branch}}, not {{other}}.', ['issue' => '7', 'branch' => 'agent/issue-7']));
    }

    public function test_a_value_holding_a_placeholder_is_not_filled_again(): void
    {
        $this->assertSame('{{base}} main', Prompt::fill('{{issue_text}} {{base}}', ['issue_text' => '{{base}}', 'base' => 'main']));
    }
}
