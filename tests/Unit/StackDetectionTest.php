<?php

namespace Tests\Unit;

use App\Guardrails\Stack;
use App\Guardrails\Stacks\Php;
use App\Guardrails\Stacks\Python;
use App\Guardrails\Stacks\Rust;
use App\Guardrails\Stacks\TypeScript;
use App\Guardrails\StackSet;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StackDetectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/foreman-stacks-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function touch(string $path, string $contents = ''): void
    {
        File::ensureDirectoryExists(dirname("{$this->root}/{$path}"));
        file_put_contents("{$this->root}/{$path}", $contents);
    }

    /** A PHP stack in its own folder, so several can be compared in one test. */
    private function phpStack(array $require, array $requireDev = []): Php
    {
        $dir = $this->root.'/php-'.uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/composer.json", json_encode(['require' => $require, 'require-dev' => $requireDev]));

        return StackSet::detect($dir)->stacks[0];
    }

    public function test_an_empty_worktree_has_no_stacks(): void
    {
        $this->assertSame([], StackSet::detect($this->root)->names());
        $this->assertSame([], StackSet::detect($this->root)->rules('tier3Paths'));
    }

    #[DataProvider('markers')]
    public function test_each_stack_is_found_from_its_marker_file(string $file, string $stack): void
    {
        $this->touch($file);

        $this->assertSame([$stack], StackSet::detect($this->root)->names());
    }

    public static function markers(): array
    {
        return [
            'php' => ['composer.json', 'php'],
            'typescript' => ['package.json', 'typescript'],
            'rust' => ['Cargo.toml', 'rust'],
            'pyproject' => ['pyproject.toml', 'python'],
            'requirements' => ['requirements-dev.txt', 'python'],
            'setup.py' => ['setup.py', 'python'],
        ];
    }

    public function test_a_nested_cargo_project_counts(): void
    {
        $this->touch('package.json', '{}');
        $this->touch('src-tauri/Cargo.toml');

        $stacks = StackSet::detect($this->root);

        $this->assertEqualsCanonicalizing(['typescript', 'rust'], $stacks->names());
        $this->assertContains('cd '.escapeshellarg('src-tauri').' && cargo test', $stacks->rules('verifyCommands', 'origin/main'));
    }

    public function test_marker_files_in_dependency_folders_are_ignored(): void
    {
        $this->touch('node_modules/left-pad/package.json');
        $this->touch('vendor/acme/lib/composer.json');
        $this->touch('target/debug/Cargo.toml');
        $this->touch('.venv/pyproject.toml');

        $this->assertSame([], StackSet::detect($this->root)->names());
    }

    public function test_a_repo_with_several_stacks_gets_the_union_of_their_rules(): void
    {
        $this->touch('composer.json', json_encode(['require' => ['laravel/framework' => '^13.0']]));
        $this->touch('package.json', '{}');
        $this->touch('pyproject.toml');

        $stacks = StackSet::detect($this->root);

        $this->assertEqualsCanonicalizing(['php', 'typescript', 'python'], $stacks->names());
        $tier3 = $stacks->rules('tier3Paths');
        $this->assertContains('routes/**', $tier3);
        $this->assertContains('middleware.ts', $tier3);
        $this->assertContains('settings.py', $tier3);
        $this->assertSame(array_values(array_unique($tier3)), $tier3);
    }

    public function test_laravel_and_codeigniter_are_told_apart(): void
    {
        $laravel = $this->phpStack(['laravel/framework' => '^13.0']);
        $ci4 = $this->phpStack(['codeigniter4/framework' => '^4.5']);

        $this->assertSame(Php::LARAVEL, $laravel->framework());
        $this->assertSame(Php::CODEIGNITER4, $ci4->framework());
        $this->assertContains('app/Policies/**', $laravel->tier3Paths());
        $this->assertNotContains('app/Policies/**', $ci4->tier3Paths());
        $this->assertContains('app/Config/Routes.php', $ci4->tier3Paths());
        $this->assertNotContains('app/Config/Routes.php', $laravel->tier3Paths());
        $this->assertRisky($laravel, 'DB::raw("select 1")');
        $this->assertNotRisky($ci4, 'DB::raw("select 1")');
        $this->assertRisky($ci4, '$builder->protect(false);');
        $this->assertNotRisky($laravel, '$builder->protect(false);');
    }

    public function test_codeigniter_shield_adds_the_auth_paths_and_patterns_only_when_installed(): void
    {
        $plain = $this->phpStack(['codeigniter4/framework' => '^4.5']);
        $shield = $this->phpStack(['codeigniter4/framework' => '^4.5', 'codeigniter4/shield' => '^1.0']);

        foreach (['Auth', 'AuthGroups', 'AuthToken', 'AuthJWT'] as $config) {
            $this->assertContains("app/Config/{$config}.php", $shield->tier3Paths());
            $this->assertNotContains("app/Config/{$config}.php", $plain->tier3Paths());
        }
        foreach (['Filters', 'Routes', 'Security', 'Cors', 'Encryption'] as $config) {
            $this->assertContains("app/Config/{$config}.php", $plain->tier3Paths());
        }
        $this->assertTrue($shield->hasShield());
        $this->assertFalse($plain->hasShield());
        $this->assertRisky($shield, '$user->addPermission("admin.access");');
        $this->assertNotRisky($plain, '$user->addPermission("admin.access");');
    }

    public function test_codeigniter_risky_code_patterns(): void
    {
        $ci4 = $this->phpStack(['codeigniter4/framework' => '^4.5']);

        $this->assertRisky($ci4, '$db->query("SELECT * FROM users WHERE id = $id");');
        $this->assertRisky($ci4, "\$db->query('SELECT * FROM users WHERE id = ' . \$id);");
        $this->assertNotRisky($ci4, "\$db->query('SELECT * FROM users WHERE id = ?', [\$id]);");
        $this->assertRisky($ci4, '$this->userModel->update($id, $this->request->getPost());');
        $this->assertRisky($ci4, "public \$globals = ['before' => ['honeypot'], 'except' => ['api/*']];");
        $this->assertRisky($ci4, '$filters->setFilter("auth");');
    }

    public function test_php_commands_follow_the_installed_tools(): void
    {
        $laravel = $this->phpStack(['laravel/framework' => '^13.0'], ['laravel/pint' => '^1.0', 'phpstan/phpstan' => '^2.0', 'pestphp/pest' => '^4.0']);
        $this->assertSame([
            "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/pint --test --)",
            'vendor/bin/phpstan analyse',
            'vendor/bin/pest',
        ], $laravel->verifyCommands('origin/main'));
        $this->assertSame('vendor/bin/pint', $laravel->formatter());

        $ci4 = $this->phpStack(['codeigniter4/framework' => '^4.5'], ['friendsofphp/php-cs-fixer' => '^3.0']);
        $this->assertSame([
            "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/develop' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=intersection --)",
            'vendor/bin/phpunit',
        ], $ci4->verifyCommands('origin/develop'));
        $this->assertSame('vendor/bin/php-cs-fixer fix', $ci4->formatter());

        $this->assertNull($this->phpStack(['codeigniter4/framework' => '^4.5'])->formatter());
    }

    public function test_typescript_commands_follow_the_lock_file_and_scripts(): void
    {
        $this->touch('package.json', json_encode(['scripts' => ['lint' => 'eslint .', 'test' => 'vitest'], 'devDependencies' => ['prettier' => '^3']]));
        $this->touch('pnpm-lock.yaml');
        $this->touch('tsconfig.json');

        $ts = StackSet::detect($this->root)->stacks[0];

        $this->assertSame(['pnpm run lint', 'npx tsc --noEmit', 'pnpm run test'], $ts->verifyCommands('origin/main'));
        $this->assertSame('npx prettier --write', $ts->formatter());
    }

    public function test_python_commands_follow_the_lock_file_and_configured_tools(): void
    {
        $this->touch('pyproject.toml', "[tool.mypy]\nstrict = true\n");
        $this->touch('uv.lock');

        $py = StackSet::detect($this->root)->stacks[0];

        $this->assertSame(
            [
                "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.py' && git ls-files -z --others --exclude-standard -- '*.py'; } | xargs -0 -r uv run ruff format --check --force-exclude --)",
                "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.py' && git ls-files -z --others --exclude-standard -- '*.py'; } | xargs -0 -r uv run ruff check --force-exclude --)",
                'uv run mypy .',
                'uv run pytest',
            ],
            $py->verifyCommands('origin/main'),
        );
        $this->assertSame('ruff format', $py->formatter());
    }

    public function test_rust_runs_its_checks_in_each_cargo_folder(): void
    {
        $this->touch('Cargo.toml');
        $this->touch('src-tauri/Cargo.toml');

        $commands = StackSet::detect($this->root)->stacks[0]->verifyCommands('origin/main');

        $this->assertCount(6, $commands);
        $this->assertContains('cargo clippy --all-targets -- -D warnings', $commands);
        $this->assertContains("cd 'src-tauri' && cargo fmt --check", $commands);
    }

    /** Every stack fills every rule table, so the integrity check and the hooks never meet an empty one. */
    #[DataProvider('stacks')]
    public function test_each_stack_exposes_its_rule_tables(string $class): void
    {
        /** @var Stack $stack */
        $stack = new $class($this->root, ['']);

        foreach (Stack::RULES as $rule) {
            $values = $stack->{$rule}();
            $this->assertNotEmpty($values, "{$class}::{$rule}() is empty");
            $this->assertSame($values, array_values(array_unique($values)), "{$class}::{$rule}() has duplicates");
        }
        foreach ($stack->riskyCode() as $pattern) {
            $this->assertNotFalse(@preg_match($pattern, ''), "Bad regex {$pattern}");
        }
        $this->assertNotEmpty($stack->name());
    }

    public static function stacks(): array
    {
        return array_map(fn ($c) => [$c], [TypeScript::class, Rust::class, Python::class]);
    }

    public function test_the_php_stack_exposes_its_rule_tables(): void
    {
        $php = $this->phpStack(['laravel/framework' => '^13.0']);

        foreach (Stack::RULES as $rule) {
            $this->assertNotEmpty($php->{$rule}(), "Php::{$rule}() is empty");
        }
        $this->assertContains('phpunit.xml*', $php->protectedConfig());
        $this->assertContains('composer.lock', $php->lockFiles());
        $this->assertContains('markTestSkipped', $php->skipMarkers());
    }

    public function test_the_rule_tables_hold_the_plan_values(): void
    {
        $this->assertContains('src-tauri/capabilities/**', (new Rust($this->root, ['']))->protectedConfig());
        $this->assertContains('Cargo.lock', (new Rust($this->root, ['']))->lockFiles());
        $this->assertContains('#[ignore]', (new Rust($this->root, ['']))->skipMarkers());
        $this->assertContains('.skip(', (new TypeScript($this->root, ['']))->skipMarkers());
        $this->assertContains('pytest.mark.xfail', (new Python($this->root, ['']))->skipMarkers());
        $this->assertContains('conftest.py', (new Python($this->root, ['']))->testFiles());
        $this->assertContains('def test_', (new Python($this->root, ['']))->testMarkers());
    }

    private function assertRisky(Stack $stack, string $code): void
    {
        $this->assertTrue($this->isRisky($stack, $code), "Expected risky: {$code}");
    }

    private function assertNotRisky(Stack $stack, string $code): void
    {
        $this->assertFalse($this->isRisky($stack, $code), "Expected not risky: {$code}");
    }

    private function isRisky(Stack $stack, string $code): bool
    {
        foreach ($stack->riskyCode() as $pattern) {
            if (preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }
}
