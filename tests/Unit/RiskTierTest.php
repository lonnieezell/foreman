<?php

namespace Tests\Unit;

use App\Guardrails\RiskTier;
use App\Guardrails\StackSet;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiskTierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/foreman-tiers-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** A unified diff (as `git diff -U0` prints it) that adds `$added` to `$path`. */
    private static function diff(string $path, array $added = ['x'], int $removed = 0): string
    {
        $lines = array_merge(array_fill(0, $removed, '-old'), array_map(fn ($l) => "+{$l}", $added));

        return "diff --git a/{$path} b/{$path}\nindex 1111111..2222222 100644\n--- a/{$path}\n+++ b/{$path}\n@@ -1,{$removed} +1,".count($added)." @@\n"
            .implode("\n", $lines)."\n";
    }

    private function tier(array $markers, string $diff): int
    {
        foreach ($markers as $file => $contents) {
            File::ensureDirectoryExists(dirname("{$this->root}/{$file}"));
            file_put_contents("{$this->root}/{$file}", is_array($contents) ? json_encode($contents) : $contents);
        }

        return RiskTier::assess(StackSet::detect($this->root), $diff)['tier'];
    }

    private const LARAVEL = ['composer.json' => ['require' => ['laravel/framework' => '*']]];

    private const CI4 = ['composer.json' => ['require' => ['codeigniter4/framework' => '*']]];

    private const CI4_SHIELD = ['composer.json' => ['require' => ['codeigniter4/framework' => '*', 'codeigniter4/shield' => '*']]];

    private const TYPESCRIPT = ['package.json' => ['name' => 'app']];

    private const TAURI = ['package.json' => ['name' => 'app'], 'src-tauri/Cargo.toml' => "[package]\nname = \"app\"\n"];

    private const PYTHON = ['pyproject.toml' => "[project]\nname = \"app\"\n"];

    public static function fixtures(): array
    {
        return [
            'laravel route' => [self::LARAVEL, self::diff('routes/web.php'), 3],
            'laravel migration' => [self::LARAVEL, self::diff('database/migrations/2026_01_01_000000_add_x.php'), 3],
            'laravel raw query' => [self::LARAVEL, self::diff('app/Models/User.php', ['$q->whereRaw("id = $id");']), 3],
            'laravel app code' => [self::LARAVEL, self::diff('app/Models/User.php', ['return $this->name;']), 2],
            'laravel tests only' => [self::LARAVEL, self::diff('tests/Feature/UserTest.php', ['$this->assertTrue(true);']), 1],
            'laravel docs only' => [self::LARAVEL, self::diff('docs/setup.md').self::diff('README.md'), 1],
            'ci4 routes' => [self::CI4, self::diff('app/Config/Routes.php'), 3],
            'ci4 filter' => [self::CI4, self::diff('app/Filters/AdminFilter.php'), 3],
            'ci4 migration' => [self::CI4, self::diff('app/Database/Migrations/2026-01-01-000000_AddX.php'), 3],
            'ci4 query with a variable' => [self::CI4, self::diff('app/Models/UserModel.php', ['$db->query("SELECT * FROM users WHERE id = $id");']), 3],
            'ci4 controller' => [self::CI4, self::diff('app/Controllers/Home.php', ['return view("home");']), 2],
            'ci4 auth config without shield' => [self::CI4, self::diff('app/Config/Auth.php'), 2],
            'shield auth config' => [self::CI4_SHIELD, self::diff('app/Config/Auth.php'), 3],
            'shield auth groups' => [self::CI4_SHIELD, self::diff('app/Config/AuthGroups.php'), 3],
            'shield permission change' => [self::CI4_SHIELD, self::diff('app/Controllers/Admin.php', ['$user->addPermission("users.delete");']), 3],
            'ci4 tests only' => [self::CI4_SHIELD, self::diff('tests/unit/HomeTest.php'), 1],
            'ts api route' => [self::TYPESCRIPT, self::diff('app/api/users/route.ts'), 3],
            'ts inner html' => [self::TYPESCRIPT, self::diff('src/View.tsx', ['<div dangerouslySetInnerHTML={{ __html: body }} />']), 3],
            'ts component' => [self::TYPESCRIPT, self::diff('src/Button.tsx', ['export const Button = () => null;']), 2],
            'ts tests only' => [self::TYPESCRIPT, self::diff('src/Button.test.tsx'), 1],
            'tauri capability' => [self::TAURI, self::diff('src-tauri/capabilities/default.json'), 3],
            'rust unsafe' => [self::TAURI, self::diff('src-tauri/src/lib.rs', ['unsafe { ptr.read() }']), 3],
            'rust code' => [self::TAURI, self::diff('src-tauri/src/lib.rs', ['let x = 1;']), 2],
            'python migration' => [self::PYTHON, self::diff('shop/migrations/0002_order.py'), 3],
            'python eval' => [self::PYTHON, self::diff('shop/views.py', ['return eval(expr)']), 3],
            'python tests only' => [self::PYTHON, self::diff('shop/test_views.py').self::diff('conftest.py'), 1],
            'no stack, docs only' => [[], self::diff('README.md'), 1],
            'no stack, code' => [[], self::diff('script.sh'), 2],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_the_tier_follows_the_diff(array $markers, string $diff, int $tier): void
    {
        $this->assertSame($tier, $this->tier($markers, $diff));
    }

    public function test_docs_and_tests_at_200_changed_lines_or_more_are_tier_2(): void
    {
        $this->assertSame(1, $this->tier(self::LARAVEL, self::diff('docs/a.md', array_fill(0, 150, 'x'), removed: 49)));
        $this->assertSame(2, $this->tier(self::LARAVEL, self::diff('docs/a.md', array_fill(0, 150, 'x'), removed: 50)));
    }

    public function test_risky_code_only_counts_on_added_lines(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -1 +1 @@\n"
            ."-\$q->whereRaw(\"id = \$id\");\n+\$q->where('id', \$id);\n";

        $this->assertSame(2, $this->tier(self::LARAVEL, $diff));
    }

    public function test_moving_a_file_out_of_a_tier_3_path_is_still_tier_3(): void
    {
        $diff = "diff --git a/routes/web.php b/app/web.php\nsimilarity index 100%\nrename from routes/web.php\nrename to app/web.php\n";

        $this->assertSame(3, $this->tier(self::LARAVEL, $diff));
    }

    public function test_the_reason_names_what_made_it_tier_3(): void
    {
        $this->tier(self::LARAVEL, '');

        $this->assertSame('touches routes/web.php', RiskTier::assess(StackSet::detect($this->root), self::diff('routes/web.php'))['reason']);
        $this->assertStringContainsString('app/Models/User.php', RiskTier::assess(StackSet::detect($this->root), self::diff('app/Models/User.php', ['DB::raw("1")']))['reason']);
    }
}
