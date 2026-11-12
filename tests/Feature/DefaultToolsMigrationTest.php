<?php

namespace Tests\Feature;

use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DefaultToolsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public static function savedLists(): array
    {
        return [
            'a custom entry' => ['Read,Edit,Bash(make:*)', ['Read', 'Edit', 'Bash(make:*)']],
            'the old default' => [
                'Read,Edit,Write,Glob,Grep,Skill,Agent,Bash(git status:*),Bash(git diff:*),Bash(git add:*),Bash(git commit:*),Bash(composer:*),Bash(./vendor/bin/*)',
                ['Read', 'Edit', 'Write', 'Glob', 'Grep', 'Skill', 'Agent', 'Bash(git status:*)', 'Bash(git diff:*)', 'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(composer:*)', 'Bash(./vendor/bin/*)'],
            ],
            'spaces after the commas' => ['Read, Bash(git:*)', ['Read', 'Bash(git:*)']],
        ];
    }

    #[DataProvider('savedLists')]
    public function test_saved_lists_gain_the_missing_defaults_and_keep_their_own_entries(string $saved, array $kept): void
    {
        $repository = Repository::create([
            'name' => 'app', 'local_path' => base_path(), 'github_repo' => 'owner/app',
            'allowed_tools' => $saved, 'setup_script' => 'true',
        ]);

        (include database_path('migrations/2026_11_11_000001_add_new_default_tools_to_repositories.php'))->up();

        $tools = explode(',', $repository->fresh()->allowed_tools);
        $this->assertSame($kept, array_slice($tools, 0, count($kept)));
        foreach (['Bash(rm:*)', 'Bash(grep:*)', 'Bash(vendor/bin/*)', 'Bash(git checkout:*)', 'Bash(cp:*)', 'Bash(php:*)'] as $added) {
            $this->assertContains($added, $tools);
        }
        $this->assertSame(count($tools), count(array_unique($tools)));
    }
}
