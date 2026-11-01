<?php

namespace Tests\Feature;

use App\Guardrails\CiKit;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class InstallCiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/foreman-ci-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function git(string $dir, string ...$args): string
    {
        $process = new Process(['git', ...$args], $dir);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** A committed repo on `main` that has an empty bare repo, `{$name}.git`, as its origin. */
    private function repo(array $files, string $name = 'repo'): string
    {
        $dir = "{$this->root}/{$name}";

        File::ensureDirectoryExists($dir);
        $this->git($dir, 'init', '--initial-branch=main');
        $this->git($dir, 'config', 'user.email', 'test@example.com');
        $this->git($dir, 'config', 'user.name', 'Test');

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$dir}/{$path}"));
            file_put_contents("{$dir}/{$path}", $contents);
        }

        $this->git($dir, 'add', '.');
        $this->git($dir, 'commit', '--message', 'initial');

        $this->git($this->root, 'init', '--bare', '--initial-branch=main', "{$name}.git");
        $this->git($dir, 'remote', 'add', 'origin', "{$this->root}/{$name}.git");

        return $dir;
    }

    private function install(string $dir): array
    {
        $this->artisan('foreman:install-ci', ['path' => $dir])->assertExitCode(0);

        return Yaml::parseFile($dir.'/'.CiKit::WORKFLOW);
    }

    /** @return list<string> The step names of a job, in order. */
    private function stepNames(array $workflow, string $job): array
    {
        return array_column($workflow['jobs'][$job]['steps'], 'name');
    }

    private function step(array $workflow, string $job, string $name): ?array
    {
        return collect($workflow['jobs'][$job]['steps'])->firstWhere('name', $name);
    }

    private const LARAVEL = '{"require":{"php":"^8.3","laravel/framework":"^13"},"require-dev":{"laravel/pint":"^1","phpstan/phpstan":"^2","rector/rector":"^2","pestphp/pest":"^4"}}';

    public function test_a_php_repo_gets_one_php_job_in_fail_fast_order(): void
    {
        $workflow = $this->install($this->repo(['composer.json' => self::LARAVEL, 'phpstan.neon' => '']));

        $this->assertSame(['php'], array_keys($workflow['jobs']));
        $this->assertSame(
            ['Formatter check', 'Rector dry run', 'PHPStan', 'Test integrity', 'Tests', 'Composer audit', 'Semgrep'],
            array_slice($this->stepNames($workflow, 'php'), 3),
        );
    }

    public function test_the_php_job_skips_phpstan_when_the_repo_has_no_phpstan_config(): void
    {
        $workflow = $this->install($this->repo(['composer.json' => self::LARAVEL]));

        $this->assertNotContains('PHPStan', $this->stepNames($workflow, 'php'));
    }

    public function test_the_php_job_skips_rector_and_phpstan_when_the_repo_has_neither(): void
    {
        $workflow = $this->install($this->repo(['composer.json' => '{"require-dev":{"laravel/pint":"^1","phpunit/phpunit":"^12"}}']));

        $this->assertSame(
            ['Formatter check', 'Test integrity', 'Tests', 'Composer audit', 'Semgrep'],
            array_slice($this->stepNames($workflow, 'php'), 3),
        );
    }

    public function test_laravel_repos_scan_with_the_php_rules_which_hold_the_laravel_ones(): void
    {
        $laravel = $this->install($this->repo(['composer.json' => self::LARAVEL]));

        // The registry has no p/laravel, and Semgrep fails the whole step on a config it can't download
        $this->assertSame('pipx run semgrep scan --config p/php --error', $this->step($laravel, 'php', 'Semgrep')['run']);
    }

    public function test_a_typescript_repo_gets_its_job(): void
    {
        $workflow = $this->install($this->repo([
            'package.json' => '{"scripts":{"lint":"eslint .","test":"vitest run"},"devDependencies":{"typescript":"^5"}}',
            'package-lock.json' => '{}',
            'tsconfig.json' => '{}',
        ]));

        $this->assertSame(['typescript'], array_keys($workflow['jobs']));
        $this->assertSame(
            ['Lint', 'Typecheck', 'Test integrity', 'Tests', 'Dependency audit', 'CodeQL init', 'CodeQL analyze', 'Semgrep'],
            array_slice($this->stepNames($workflow, 'typescript'), -8),
        );
        $this->assertContains('npm ci', array_column($workflow['jobs']['typescript']['steps'], 'run'));
    }

    public function test_a_rust_repo_gets_its_job(): void
    {
        $workflow = $this->install($this->repo(['Cargo.toml' => '[package]']));

        $this->assertSame(
            ['Format check', 'Clippy', 'Test integrity', 'Tests', 'Install cargo-audit', 'Cargo audit', 'CodeQL init', 'CodeQL analyze', 'Semgrep'],
            array_slice($this->stepNames($workflow, 'rust'), -9),
        );
    }

    public function test_a_rust_repo_with_a_deny_file_uses_cargo_deny(): void
    {
        $workflow = $this->install($this->repo(['Cargo.toml' => '[package]', 'deny.toml' => '']));

        $this->assertContains('Cargo deny', $this->stepNames($workflow, 'rust'));
        $this->assertNotContains('Cargo audit', $this->stepNames($workflow, 'rust'));
    }

    public function test_a_python_repo_gets_its_job(): void
    {
        $workflow = $this->install($this->repo(['pyproject.toml' => self::PYTHON]));

        $this->assertSame(
            ['Ruff format check', 'Ruff check', 'Mypy', 'Test integrity', 'Tests', 'Dependency audit', 'CodeQL init', 'CodeQL analyze', 'Semgrep'],
            array_slice($this->stepNames($workflow, 'python'), -9),
        );
        $this->assertSame('pytest || [ $? -eq 5 ]', $this->step($workflow, 'python', 'Tests')['run'], 'no tests collected is not a failure');
    }

    public function test_a_python_repo_without_ruff_or_pytest_gets_no_steps_for_them(): void
    {
        $workflow = $this->install($this->repo(['pyproject.toml' => "[project]\nname = \"app\"\n"]));
        $names = $this->stepNames($workflow, 'python');

        $this->assertNotContains('Ruff format check', $names);
        $this->assertNotContains('Ruff check', $names);
        $this->assertNotContains('Tests', $names);
        $this->assertSame('pip install -e . pip-audit', $this->step($workflow, 'python', 'Install dependencies')['run']);
    }

    private const PYTHON = "[tool.mypy]\nstrict = true\n[tool.ruff]\n[tool.pytest.ini_options]\n";

    public function test_a_multi_stack_repo_gets_one_job_per_stack(): void
    {
        $workflow = $this->install($this->repo([
            'composer.json' => self::LARAVEL,
            'package.json' => '{}',
            'src-tauri/Cargo.toml' => '[package]',
            'api/pyproject.toml' => '',
        ]));

        $this->assertEqualsCanonicalizing(['php', 'typescript', 'rust', 'python'], array_keys($workflow['jobs']));
        $this->assertSame('src-tauri', $workflow['jobs']['rust']['defaults']['run']['working-directory']);
        $this->assertSame('api', $workflow['jobs']['python']['defaults']['run']['working-directory']);
        $this->assertArrayNotHasKey('defaults', $workflow['jobs']['php']);
    }

    public function test_every_job_runs_the_integrity_script_with_a_pinned_php(): void
    {
        $workflow = $this->install($this->repo(['package.json' => '{}', 'Cargo.toml' => '[package]']));

        foreach ($workflow['jobs'] as $job) {
            $setup = collect($job['steps'])->firstWhere('uses', 'shivammathur/setup-php@v2');
            $integrity = collect($job['steps'])->firstWhere('name', 'Test integrity');

            $this->assertSame(CiKit::PHP_VERSION, $setup['with']['php-version']);
            $this->assertStringContainsString(CiKit::SCRIPT, $integrity['run']);
            $this->assertSame(0, $job['steps'][0]['with']['fetch-depth']);
        }
    }

    public function test_codeql_runs_on_public_repos_and_semgrep_takes_over_on_private_ones(): void
    {
        $workflow = $this->install($this->repo(['package.json' => '{}']));

        $this->assertSame('${{ !github.event.repository.private }}', $this->step($workflow, 'typescript', 'CodeQL analyze')['if']);
        $this->assertSame('${{ github.event.repository.private }}', $this->step($workflow, 'typescript', 'Semgrep')['if']);
        $this->assertSame('write', $workflow['jobs']['typescript']['permissions']['security-events']);
        $this->assertStringContainsString('GitHub Advanced Security', file_get_contents($this->root.'/repo/'.CiKit::WORKFLOW));
    }

    public function test_it_writes_on_a_new_branch_and_never_pushes(): void
    {
        $dir = $this->repo(['composer.json' => self::LARAVEL]);

        $this->install($dir);

        $this->assertSame('foreman/ci-kit', $this->git($dir, 'branch', '--show-current'));
        $this->assertSame('main', $this->git($dir, 'rev-parse', '--abbrev-ref', 'main'));
        $this->assertNotContains(CiKit::WORKFLOW, explode("\n", $this->git($dir, 'ls-tree', '-r', '--name-only', 'main')));
        $this->assertContains(CiKit::WORKFLOW, explode("\n", $this->git($dir, 'show', '--name-only', '--format=', 'HEAD')));
        $this->assertSame('', $this->git($dir, 'status', '--porcelain'));
        $this->assertSame('', $this->git($this->root.'/repo.git', 'branch', '--list'));
    }

    public function test_the_branch_name_can_be_chosen(): void
    {
        $dir = $this->repo(['package.json' => '{}']);

        $this->artisan('foreman:install-ci', ['path' => $dir, '--branch' => 'ci/guardrails'])->assertExitCode(0);

        $this->assertSame('ci/guardrails', $this->git($dir, 'branch', '--show-current'));
    }

    public function test_the_script_copy_is_the_one_foreman_ships(): void
    {
        $dir = $this->repo(['package.json' => '{}']);

        $this->install($dir);

        $this->assertFileEquals(resource_path('guardrails/test-integrity.php'), $dir.'/'.CiKit::SCRIPT);
    }

    public function test_the_copied_script_passes_its_own_integrity_check_in_the_pull_request_that_adds_it(): void
    {
        $dir = $this->repo(['composer.json' => '{}']);

        $this->install($dir);

        $check = new Process(['php', $dir.'/'.CiKit::SCRIPT, '--base=main', '--dir='.$dir]);
        $check->run();

        $this->assertSame(0, $check->getExitCode(), $check->getOutput().$check->getErrorOutput());
    }

    public function test_it_refuses_a_repo_with_no_known_stack_and_leaves_it_alone(): void
    {
        $dir = $this->repo(['README.md' => 'hi']);

        $this->artisan('foreman:install-ci', ['path' => $dir])->assertExitCode(1);

        $this->assertSame('main', $this->git($dir, 'branch', '--show-current'));
    }

    public function test_it_refuses_a_path_that_is_not_a_git_checkout(): void
    {
        File::ensureDirectoryExists($this->root.'/plain');

        $this->artisan('foreman:install-ci', ['path' => $this->root.'/plain'])->assertExitCode(1);
    }

    public function test_a_path_inside_the_repo_writes_at_the_repo_root(): void
    {
        $dir = $this->repo(['composer.json' => self::LARAVEL, 'backend/README.md' => 'hi']);

        $this->artisan('foreman:install-ci', ['path' => $dir.'/backend'])->assertExitCode(0);

        $this->assertFileExists($dir.'/'.CiKit::WORKFLOW);
        $this->assertFileDoesNotExist($dir.'/backend/'.CiKit::WORKFLOW);
    }

    public function test_semgrep_runs_through_pipx_so_the_system_python_is_left_alone(): void
    {
        $workflow = $this->install($this->repo(['composer.json' => self::LARAVEL]));

        $semgrep = $this->step($workflow, 'php', 'Semgrep')['run'];

        $this->assertStringStartsWith('pipx run semgrep scan', $semgrep);
        $this->assertStringNotContainsString('pip install', $semgrep);
    }

    public function test_the_integrity_step_prefers_the_base_branchs_copy_of_the_script(): void
    {
        $workflow = $this->install($this->repo(['package.json' => '{}']));

        $integrity = $this->step($workflow, 'typescript', 'Test integrity')['run'];

        $this->assertStringContainsString('git show "origin/${{ github.base_ref }}:'.CiKit::SCRIPT.'"', $integrity);
    }

    public function test_pnpm_gets_a_version_only_when_package_json_does_not_name_one(): void
    {
        $pnpmSetup = function (string $packageJson, string $name) {
            $workflow = $this->install($this->repo(['package.json' => $packageJson, 'pnpm-lock.yaml' => ''], $name));

            return $this->step($workflow, 'typescript', 'Set up pnpm');
        };

        $this->assertSame('latest', $pnpmSetup('{}', 'unpinned')['with']['version']);
        $this->assertArrayNotHasKey('with', $pnpmSetup('{"packageManager":"pnpm@9.15.0"}', 'pinned'));
    }

    public function test_yarn_berry_installs_and_audits_with_its_own_commands(): void
    {
        $workflow = $this->install($this->repo(['package.json' => '{}', 'yarn.lock' => '', '.yarnrc.yml' => '']));

        $runs = array_column($workflow['jobs']['typescript']['steps'], 'run');

        $this->assertContains('yarn install --immutable', $runs);
        $this->assertContains('yarn npm audit', $runs);
    }

    public function test_uv_and_poetry_projects_bring_their_own_ruff_and_pytest(): void
    {
        $uv = $this->install($this->repo(['pyproject.toml' => self::PYTHON, 'uv.lock' => '']));
        $runs = array_column($uv['jobs']['python']['steps'], 'run');

        $this->assertContains('uv run --with ruff ruff format --check', $runs);
        $this->assertContains('uv run --with pytest pytest || [ $? -eq 5 ]', $runs);

        $poetry = $this->install($this->repo(['pyproject.toml' => self::PYTHON, 'poetry.lock' => ''], 'poetry'));
        $install = $this->step($poetry, 'python', 'Install dependencies')['run'];

        $this->assertStringContainsString('poetry run pip install ruff pytest', $install);
    }

    public function test_a_laravel_app_gets_an_env_file_and_key_before_its_tests(): void
    {
        $workflow = $this->install($this->repo(['composer.json' => self::LARAVEL, '.env.example' => 'APP_KEY=']));
        $names = $this->stepNames($workflow, 'php');

        $env = $this->step($workflow, 'php', 'Prepare Laravel environment');

        $this->assertSame("cp .env.example .env\nphp artisan key:generate", $env['run']);
        $this->assertLessThan(array_search('Tests', $names), array_search('Prepare Laravel environment', $names));
    }

    public function test_a_laravel_app_with_vite_builds_its_assets_before_its_tests(): void
    {
        $workflow = $this->install($this->repo([
            'composer.json' => self::LARAVEL, 'vite.config.js' => '', 'package.json' => '{"scripts":{"build":"vite build"}}', 'package-lock.json' => '{}',
        ]));
        $names = $this->stepNames($workflow, 'php');

        $this->assertSame(['node-version' => 'lts/*', 'cache' => 'npm', 'cache-dependency-path' => 'package-lock.json'], $this->step($workflow, 'php', 'Set up Node')['with']);
        $this->assertSame('npm ci', $this->step($workflow, 'php', 'Install Node dependencies')['run']);
        $this->assertSame('npm run build', $this->step($workflow, 'php', 'Build assets')['run']);
        $this->assertSame('ramsey/composer-install@v3', $this->step($workflow, 'php', 'Install dependencies')['uses'], 'composer keeps its own step');
        $this->assertLessThan(array_search('Set up Node', $names), array_search('Test integrity', $names), 'cheap checks still come first');
        $this->assertLessThan(array_search('Tests', $names), array_search('Build assets', $names));
    }

    public function test_the_asset_build_uses_the_apps_package_manager(): void
    {
        $workflow = $this->install($this->repo([
            'composer.json' => self::LARAVEL, 'vite.config.ts' => '', 'package.json' => '{"scripts":{"build":"vite build"}}', 'pnpm-lock.yaml' => '',
        ]));

        $this->assertSame('pnpm install --frozen-lockfile', $this->step($workflow, 'php', 'Install Node dependencies')['run']);
        $this->assertSame('pnpm run build', $this->step($workflow, 'php', 'Build assets')['run']);
    }

    public function test_assets_are_not_built_without_a_vite_config_a_build_script_or_laravel(): void
    {
        $noVite = $this->install($this->repo(['composer.json' => self::LARAVEL, 'package.json' => '{"scripts":{"build":"tsc"}}'], 'novite'));
        $noScript = $this->install($this->repo(['composer.json' => self::LARAVEL, 'vite.config.js' => '', 'package.json' => '{}'], 'noscript'));
        $plainPhp = $this->install($this->repo(['composer.json' => '{}', 'vite.config.js' => '', 'package.json' => '{"scripts":{"build":"vite build"}}'], 'plain'));

        foreach ([$noVite, $noScript, $plainPhp] as $workflow) {
            $this->assertNotContains('Build assets', $this->stepNames($workflow, 'php'));
            $this->assertNotContains('Set up Node', $this->stepNames($workflow, 'php'));
        }
    }

    public function test_php_installs_through_composer_install_which_caches_dependencies(): void
    {
        $root = $this->install($this->repo(['composer.json' => self::LARAVEL]));
        $nested = $this->install($this->repo(['backend/composer.json' => self::LARAVEL], 'nested'));

        $this->assertSame('ramsey/composer-install@v3', $this->step($root, 'php', 'Install dependencies')['uses']);
        $this->assertArrayNotHasKey('with', $this->step($root, 'php', 'Install dependencies'));
        $this->assertSame('backend', $this->step($nested, 'php', 'Install dependencies')['with']['working-directory']);
    }

    public function test_node_caches_by_its_lock_file_only_when_there_is_one(): void
    {
        $pnpm = $this->install($this->repo(['web/package.json' => '{}', 'web/pnpm-lock.yaml' => ''], 'pnpm'));
        $noLock = $this->install($this->repo(['package.json' => '{}'], 'nolock'));

        $this->assertSame(['node-version' => 'lts/*', 'cache' => 'pnpm', 'cache-dependency-path' => 'web/pnpm-lock.yaml'], $this->step($pnpm, 'typescript', 'Set up Node')['with']);
        $this->assertArrayNotHasKey('cache', $this->step($noLock, 'typescript', 'Set up Node')['with']);
    }

    public function test_python_caches_for_each_install_path(): void
    {
        $pip = $this->install($this->repo(['api/pyproject.toml' => '', 'api/requirements.txt' => ''], 'pip'));
        $uv = $this->install($this->repo(['pyproject.toml' => '', 'uv.lock' => ''], 'uv'));
        $poetry = $this->install($this->repo(['pyproject.toml' => '', 'poetry.lock' => ''], 'poetry'));

        $this->assertSame(['python-version' => '3.12', 'cache' => 'pip', 'cache-dependency-path' => 'api/requirements.txt'], $this->step($pip, 'python', 'Set up Python')['with']);
        $this->assertTrue($this->step($uv, 'python', 'Set up uv')['with']['enable-cache']);
        $this->assertSame(['path' => '~/.cache/pypoetry', 'key' => "poetry-\${{ runner.os }}-\${{ hashFiles('poetry.lock') }}"], $this->step($poetry, 'python', 'Cache Poetry')['with']);
    }

    public function test_rust_caches_its_build_in_the_stacks_folder(): void
    {
        $root = $this->install($this->repo(['Cargo.toml' => '[package]']));
        $tauri = $this->install($this->repo(['package.json' => '{}', 'src-tauri/Cargo.toml' => '[package]'], 'tauri'));

        $this->assertSame('Swatinem/rust-cache@v2', $this->step($root, 'rust', 'Cache Rust build')['uses']);
        $this->assertArrayNotHasKey('with', $this->step($root, 'rust', 'Cache Rust build'));
        $this->assertSame('src-tauri', $this->step($tauri, 'rust', 'Cache Rust build')['with']['workspaces']);
    }

    public function test_a_failed_commit_puts_the_repo_back_as_it_was(): void
    {
        $dir = $this->repo(['package.json' => '{}']);
        file_put_contents($dir.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        chmod($dir.'/.git/hooks/pre-commit', 0755);

        $this->artisan('foreman:install-ci', ['path' => $dir])->assertExitCode(1);

        $this->assertSame('main', $this->git($dir, 'branch', '--show-current'));
        $this->assertSame('', $this->git($dir, 'branch', '--list', 'foreman/ci-kit'));
        $this->assertSame('', $this->git($dir, 'status', '--porcelain', '--untracked-files=all'));
    }

    public function test_it_does_not_overwrite_an_existing_workflow(): void
    {
        $dir = $this->repo(['package.json' => '{}', CiKit::WORKFLOW => 'mine: true']);

        $this->artisan('foreman:install-ci', ['path' => $dir])->assertExitCode(1);

        $this->assertSame('mine: true', file_get_contents($dir.'/'.CiKit::WORKFLOW));
        $this->assertSame('main', $this->git($dir, 'branch', '--show-current'));
    }
}
