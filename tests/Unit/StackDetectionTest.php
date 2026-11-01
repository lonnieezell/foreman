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
use Symfony\Component\Process\Process;
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
    private function phpStack(array $require, array $requireDev = [], array $files = []): Php
    {
        $dir = $this->root.'/php-'.uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/composer.json", json_encode(['require' => $require, 'require-dev' => $requireDev]));

        foreach ($files as $file) {
            file_put_contents("{$dir}/{$file}", '');
        }

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
        $this->assertContains('(cd '.escapeshellarg('src-tauri').' && cargo test)', $stacks->verifyCommands('origin/main'));
    }

    public function test_marker_files_in_dependency_folders_are_ignored(): void
    {
        $this->touch('node_modules/left-pad/package.json');
        $this->touch('vendor/acme/lib/composer.json');
        $this->touch('target/debug/Cargo.toml');
        $this->touch('.venv/pyproject.toml');

        $this->assertSame([], StackSet::detect($this->root)->names());
    }

    public function test_marker_files_in_test_fixture_and_example_folders_are_ignored(): void
    {
        $this->touch('composer.json', '{}');
        $this->touch('tests/fixtures/app/composer.json', '{}');
        $this->touch('src/__fixtures__/web/package.json', '{}');
        $this->touch('examples/demo/Cargo.toml');
        $this->touch('stubs/lib/pyproject.toml');
        $this->touch('testdata/requirements.txt');

        $stacks = StackSet::detect($this->root);

        $this->assertSame(['php'], $stacks->names());
        $this->assertSame(['vendor/bin/phpunit'], $stacks->verifyCommands('origin/main'));
    }

    public function test_marker_files_in_git_ignored_folders_are_ignored(): void
    {
        (new Process(['git', 'init', '--quiet'], $this->root))->mustRun();
        $this->touch('composer.json', '{}');
        $this->touch('.gitignore', "/storage/foreman\n");
        $this->touch('storage/foreman/worktrees/other-app/issue-1/src-tauri/Cargo.toml');

        $this->assertSame(['php'], StackSet::detect($this->root)->names());
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
        $laravel = $this->phpStack(['laravel/framework' => '^13.0'], ['laravel/pint' => '^1.0', 'phpstan/phpstan' => '^2.0', 'pestphp/pest' => '^4.0'], ['phpstan.neon']);
        $this->assertSame([
            "(set -o pipefail; { git diff -z --name-only --relative --diff-filter=d --merge-base 'origin/main' -- '*.php' && git ls-files -z --others --exclude-standard -- '*.php'; } | xargs -0 -r vendor/bin/pint --test --)",
            'vendor/bin/phpstan analyse',
            'vendor/bin/pest',
        ], $laravel->verifyCommands('origin/main'));
        $this->assertSame('vendor/bin/pint', $laravel->formatter());

        $ci4 = $this->phpStack(['codeigniter4/framework' => '^4.5'], ['friendsofphp/php-cs-fixer' => '^3.0']);
        [$fixer, $phpunit] = $ci4->verifyCommands('origin/develop');
        $this->assertStringContainsString("--merge-base 'origin/develop' -- '*.php'", $fixer);
        $this->assertStringEndsWith('| xargs -0 -r vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode=intersection --)', $fixer);
        $this->assertSame('vendor/bin/phpunit', $phpunit);
        $this->assertSame('vendor/bin/php-cs-fixer fix', $ci4->formatter());

        $this->assertNull($this->phpStack(['codeigniter4/framework' => '^4.5'])->formatter());
    }

    public function test_phpstan_runs_only_with_a_config_since_without_one_it_has_no_paths(): void
    {
        $noConfig = $this->phpStack([], ['phpstan/phpstan' => '^2.0']);
        $this->assertSame(['vendor/bin/phpunit'], $noConfig->verifyCommands('origin/main'));
        $this->assertNotContains('PHPStan', array_column($noConfig->ciSteps()['lint'], 'name'));

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $config) {
            $stack = $this->phpStack([], ['phpstan/phpstan' => '^2.0'], [$config]);

            $this->assertContains('vendor/bin/phpstan analyse', $stack->verifyCommands('origin/main'), $config);
            $this->assertContains('PHPStan', array_column($stack->ciSteps()['lint'], 'name'), $config);
        }
    }

    public function test_an_agent_worktree_inside_a_repo_that_ignores_it_still_finds_its_stacks(): void
    {
        // Foreman keeps agent worktrees under its own storage/foreman, which its .gitignore excludes
        $foreman = "{$this->root}/foreman";
        File::ensureDirectoryExists($foreman);
        (new Process(['git', 'init', '--quiet'], $foreman))->mustRun();
        file_put_contents("{$foreman}/.gitignore", "/storage/foreman\n");

        $app = "{$this->root}/app";
        File::ensureDirectoryExists($app);
        file_put_contents("{$app}/composer.json", '{}');
        foreach ([['init', '--quiet'], ['add', '.'], ['-c', 'user.name=T', '-c', 'user.email=t@example.test', 'commit', '--quiet', '-m', 'init']] as $args) {
            (new Process(['git', ...$args], $app))->mustRun();
        }

        // A worktree has a .git file, not a folder, so the git root has to stop there
        $worktree = "{$foreman}/storage/foreman/worktrees/app/issue-1";
        (new Process(['git', 'worktree', 'add', '--quiet', '-b', 'agent/issue-1', $worktree], $app))->mustRun();

        $this->assertSame(['php'], StackSet::detect($worktree)->names());
    }

    public function test_typescript_commands_follow_the_lock_file_and_scripts(): void
    {
        $this->touch('package.json', json_encode(['scripts' => ['lint' => 'eslint .', 'test' => 'vitest'], 'devDependencies' => ['prettier' => '^3', 'typescript' => '^5']]));
        $this->touch('pnpm-lock.yaml');
        $this->touch('tsconfig.json');

        $ts = StackSet::detect($this->root)->stacks[0];

        $this->assertSame(['pnpm run lint', 'npx tsc --noEmit', 'pnpm run test'], $ts->verifyCommands('origin/main'));
        $this->assertSame('npx prettier --write', $ts->formatter());
    }

    public function test_a_typescript_check_runs_only_when_it_can_pass(): void
    {
        // npm init's placeholder test script always exits 1, and npx without TypeScript fetches an unrelated `tsc` package
        $this->touch('package.json', json_encode(['scripts' => ['test' => 'echo "Error: no test specified" && exit 1']]));
        $this->touch('tsconfig.json');

        $ts = StackSet::detect($this->root)->stacks[0];

        $this->assertSame([], $ts->verifyCommands('origin/main'));
        $this->assertSame([], $ts->ciSteps()['test']);
        $this->assertSame([], $ts->ciSteps()['lint']);
    }

    public function test_python_commands_follow_the_lock_file_and_configured_tools(): void
    {
        $this->touch('pyproject.toml', "[tool.mypy]\nstrict = true\n[tool.ruff]\nline-length = 100\n[tool.pytest.ini_options]\n");
        $this->touch('uv.lock');

        $py = StackSet::detect($this->root)->stacks[0];

        $commands = $py->verifyCommands('origin/main');
        [$format, $lint] = $commands;
        $this->assertStringContainsString("--merge-base 'origin/main' -- '*.py'", $format);
        $this->assertStringEndsWith('| xargs -0 -r uv run --with ruff ruff format --check --force-exclude --)', $format);
        $this->assertStringEndsWith('| xargs -0 -r uv run --with ruff ruff check --force-exclude --)', $lint);
        $this->assertSame(['uv run --with mypy mypy .', 'uv run --with pytest pytest || [ $? -eq 5 ]'], array_slice($commands, 2));
        $this->assertSame('ruff format', $py->formatter());
    }

    public function test_python_runs_ruff_and_pytest_only_when_the_project_uses_them(): void
    {
        $this->touch('pyproject.toml', "[project]\nname = \"app\"\n");

        $py = StackSet::detect($this->root)->stacks[0];

        $this->assertSame([], $py->verifyCommands('origin/main'));
        $this->assertNull($py->formatter(), 'a project without ruff is never reformatted with it');
        $this->assertSame([], $py->ciSteps()['lint']);
        $this->assertSame([], $py->ciSteps()['test']);
        $this->assertStringNotContainsString('ruff', $py->ciSteps()['setup'][1]['run']);
    }

    #[DataProvider('pythonToolUses')]
    public function test_python_tools_count_when_configured_or_declared(string $file, string $contents): void
    {
        $this->touch('pyproject.toml', "[project]\nname = \"app\"\n");
        $this->touch($file, $contents);

        $commands = implode("\n", StackSet::detect($this->root)->stacks[0]->verifyCommands('origin/main'));

        $this->assertStringContainsString('ruff check', $commands);
        $this->assertStringContainsString('pytest || [ $? -eq 5 ]', $commands);
    }

    public static function pythonToolUses(): array
    {
        return [
            'requirements' => ['requirements-dev.txt', "ruff==0.6.0\npytest>=8\n"],
            'pyproject dependency group' => ['pyproject.toml', "[project]\nname = \"app\"\n[dependency-groups]\ndev = [\"ruff>=0.5\", \"pytest\"]\n"],
            'poetry' => ['pyproject.toml', "[tool.poetry.group.dev.dependencies]\nruff = \"^0.5\"\npytest = \"^8\"\n"],
        ];
    }

    public function test_python_ruff_and_pytest_configs_count_without_a_dependency(): void
    {
        $this->touch('pyproject.toml', "[project]\nname = \"app\"\n");
        $this->touch('ruff.toml');
        $this->touch('conftest.py');

        $commands = implode("\n", StackSet::detect($this->root)->stacks[0]->verifyCommands('origin/main'));

        $this->assertStringContainsString('ruff check', $commands);
        $this->assertStringContainsString('pytest || [ $? -eq 5 ]', $commands);
    }

    public function test_rust_runs_its_checks_in_each_cargo_folder(): void
    {
        $this->touch('Cargo.toml');
        $this->touch('src-tauri/Cargo.toml');

        $commands = StackSet::detect($this->root)->stacks[0]->verifyCommands('origin/main');

        $this->assertCount(6, $commands);
        $this->assertContains('cargo clippy --all-targets -- -D warnings', $commands);
        $this->assertContains("(cd 'src-tauri' && cargo fmt --check)", $commands);
    }

    public function test_a_default_script_runs_every_command_in_the_folder_it_names(): void
    {
        $verify = $this->subFolderThenRootScript();

        $run = $this->runVerify($verify);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([
            "{$this->root}/frontend pnpm run lint",
            "{$this->root}/frontend pnpm run test",
            "{$this->root} cargo fmt --check",
            "{$this->root} cargo clippy --all-targets -- -D warnings",
            "{$this->root} cargo test",
        ], $this->toolLog());
    }

    public function test_a_missing_sub_folder_fails_the_default_script(): void
    {
        $verify = $this->subFolderThenRootScript();
        File::deleteDirectory("{$this->root}/frontend");

        $run = $this->runVerify($verify);

        $this->assertNotSame(0, $run->getExitCode());
        $this->assertSame([], $this->toolLog());
    }

    /** A TypeScript stack with two checks in `frontend/`, then a Rust stack at the root. */
    private function subFolderThenRootScript(): string
    {
        $this->touch('frontend/package.json', json_encode(['scripts' => ['lint' => 'eslint .', 'test' => 'vitest']]));
        $this->touch('frontend/pnpm-lock.yaml');
        $this->touch('Cargo.toml');

        return implode("\n", StackSet::detect($this->root)->verifyCommands('origin/main'));
    }

    /** Run `$verify` the way the Stop hook and the gate do, with stub tools that log the folder they ran in. */
    private function runVerify(string $verify): Process
    {
        $bin = "{$this->root}/.bin";
        File::ensureDirectoryExists($bin);
        foreach (['pnpm', 'cargo'] as $tool) {
            file_put_contents("{$bin}/{$tool}", "#!/bin/sh\necho \"\$(pwd -P) {$tool} \$*\" >> '{$this->root}/.tools.log'\n");
            chmod("{$bin}/{$tool}", 0755);
        }

        $process = new Process(['bash', '-ec', $verify], $this->root, ['PATH' => $bin.':'.getenv('PATH')]);
        $process->run();

        return $process;
    }

    /** @return list<string> */
    private function toolLog(): array
    {
        $log = "{$this->root}/.tools.log";
        $root = realpath($this->root);

        return is_file($log) ? array_map(fn ($line) => str_replace($root, $this->root, $line), file($log, FILE_IGNORE_NEW_LINES)) : [];
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
        $this->assertContains('registry.npmjs.org', (new TypeScript($this->root, ['']))->networkHosts());
        $this->assertContains('static.crates.io', (new Rust($this->root, ['']))->networkHosts());
        $this->assertContains('files.pythonhosted.org', (new Python($this->root, ['']))->networkHosts());
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
