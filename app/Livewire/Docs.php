<?php

namespace App\Livewire;

use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Docs extends Component
{
    // Sidebar order. Also the allowlist: only these files can be loaded.
    public const PAGES = [
        'getting-started' => 'Getting started',
        'runs' => 'Runs and statuses',
        'guardrails' => 'Guardrails',
        'repository-settings' => 'Repository settings',
        'global-settings' => 'Global settings',
        'usage-limits' => 'Usage limits and profiles',
        'pr-watching' => 'PR watching',
        'troubleshooting' => 'Troubleshooting',
    ];

    #[Locked]
    public string $page;

    public function mount(string $page): void
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        $this->page = $page;
    }

    // Pages link to each other as `slug.md` so the links also work on GitHub
    public static function html(string $markdown): string
    {
        return preg_replace_callback(
            '/href="([a-z0-9-]+)\.md(#[^"]*)?"/',
            fn ($m) => 'href="'.route('docs', $m[1]).($m[2] ?? '').'"',
            Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                // Ids on headings, so the UI can link to a section. No visible permalink.
                'heading_permalink' => ['apply_id_to_heading' => true, 'id_prefix' => '', 'fragment_prefix' => '', 'insert' => 'none'],
            ], [new HeadingPermalinkExtension]),
        );
    }

    public function render()
    {
        return view('livewire.docs', [
            'html' => self::html(file_get_contents(base_path("docs/{$this->page}.md"))),
        ])->title(self::PAGES[$this->page].' · Docs');
    }
}
