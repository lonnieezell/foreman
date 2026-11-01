<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Livewire\Docs;
use App\Models\Repository;
use App\Services\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_docs_index_opens_getting_started(): void
    {
        $this->get('/docs')->assertRedirect('/docs/getting-started');
    }

    public function test_links_between_pages_point_at_the_docs_route(): void
    {
        $html = Docs::html('[Start](getting-started.md) and [section](getting-started.md#a-section) and [GitHub](https://github.com)');

        $this->assertStringContainsString('href="'.route('docs', 'getting-started').'"', $html);
        $this->assertStringContainsString('href="'.route('docs', 'getting-started').'#a-section"', $html);
        $this->assertStringContainsString('href="https://github.com"', $html);
    }

    public function test_headings_get_ids_for_linking(): void
    {
        $html = Docs::html("## Max turns\n\nText.");

        $this->assertMatchesRegularExpression('/<h2[^>]*id="max-turns"/', $html);
    }

    public function test_every_listed_page_has_a_file_and_every_file_is_listed(): void
    {
        $files = array_map(fn ($f) => basename($f, '.md'), glob(base_path('docs/*.md')));

        $this->assertEqualsCanonicalizing(array_keys(Docs::PAGES), $files);
    }

    public function test_every_page_renders_its_markdown(): void
    {
        foreach (Docs::PAGES as $slug => $title) {
            $this->get(route('docs', $slug))->assertOk()->assertSee('<h1', false)->assertSee($title);
        }
    }

    public function test_the_guardrails_page_renders_with_its_sections_and_stack_table(): void
    {
        $this->get(route('docs', 'guardrails'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('The pipeline at a glance')
            ->assertSee('id="stack-rules"', false)
            ->assertSee('<table', false)
            ->assertSee('src-tauri/capabilities/**')
            ->assertSee('id="known-gaps"', false)
            ->assertSee('The setup script and the checks run unsandboxed');
    }

    public function test_the_guardrails_links_to_other_pages_resolve(): void
    {
        $markdown = file_get_contents(base_path('docs/guardrails.md'));
        preg_match_all('/\]\(([a-z0-9-]+)\.md(#[a-z0-9-]+)?\)/', $markdown, $links, PREG_SET_ORDER);

        $this->assertNotEmpty($links);
        foreach ($links as $link) {
            $this->assertArrayHasKey($link[1], Docs::PAGES, "guardrails.md links to missing page {$link[1]}");

            if (isset($link[2])) {
                $this->get(route('docs', $link[1]))->assertSee('id="'.substr($link[2], 1).'"', false);
            }
        }
    }

    public function test_the_guardrails_page_is_in_the_readme_docs_list(): void
    {
        $this->assertStringContainsString('(docs/guardrails.md)', file_get_contents(base_path('README.md')));
    }

    public function test_the_sidebar_links_every_page(): void
    {
        $response = $this->get(route('docs', 'runs'));

        foreach (array_keys(Docs::PAGES) as $slug) {
            $response->assertSee('href="'.route('docs', $slug).'"', false);
        }
    }

    public function test_the_masthead_links_to_the_docs(): void
    {
        $this->get(route('settings'))->assertSee('href="'.route('docs', 'getting-started').'"', false);
    }

    public function test_confusing_spots_link_to_the_matching_docs(): void
    {
        Process::fake();
        config(['foreman.claude_profiles' => ['work' => '/home/me/.claude-work']]);
        $repo = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app', 'claude_profile' => 'work',
            'allowed_tools' => Repository::DEFAULT_ALLOWED_TOOLS, 'setup_script' => 'true',
        ]);
        $run = fn (RunStatus $status) => $repo->runs()->create([
            'issue_number' => 7, 'branch' => 'agent/issue-7', 'model' => 'sonnet', 'status' => $status,
        ]);
        app(Usage::class)->record('work', [
            'status' => 'allowed', 'resetsAt' => now()->timestamp + 3600, 'rateLimitType' => 'five_hour',
            'unifiedWindows' => ['five_hour' => ['utilization' => 0.95, 'resetsAt' => now()->timestamp + 3600, 'status' => 'allowed']],
        ]);
        $link = fn (string $page, string $anchor = '') => 'href="'.route('docs', $page).$anchor.'"';

        $this->get(route('repositories.edit', $repo))->assertSee($link('repository-settings'), false);
        $this->get(route('settings'))->assertSee($link('global-settings'), false);
        $this->get('/')->assertSee('Holding new runs on the work profile.')->assertSee($link('usage-limits', '#when-runs-are-held'), false);
        $this->get(route('runs.show', $run(RunStatus::NeedsHuman)))->assertSee($link('runs', '#needs-you'), false);
        $this->get(route('runs.show', $run(RunStatus::Failed)))->assertSee($link('troubleshooting', '#a-run-failed'), false);
    }

    public function test_raw_html_and_unsafe_links_are_not_rendered(): void
    {
        $html = Docs::html("<script>alert(1)</script>\n\n[x](javascript:alert(1))");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_the_page_cannot_be_changed_after_mount(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Docs::class, ['page' => 'runs'])->set('page', '../README');
    }

    public function test_unknown_pages_are_not_found(): void
    {
        $this->get('/docs/nope')->assertNotFound();
        $this->get('/docs/..%2F.env')->assertNotFound();
        $this->get('/docs/README')->assertNotFound();
    }
}
