<?php

namespace Tests\Feature;

use App\Guardrails\Stacks\Php;
use App\Guardrails\Stacks\Python;
use App\Guardrails\Stacks\Rust;
use App\Guardrails\Stacks\TypeScript;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestIntegrityScriptTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/foreman-integrity-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    /** One small project per stack: the files, which one is a test file, its parts, and one config file. */
    public static function stacks(): array
    {
        $php = fn (string $composer) => [
            'files' => ['composer.json' => $composer, 'phpunit.xml' => '<phpunit/>', 'app/Thing.php' => '<?php class Thing {}'],
            'test' => 'tests/Feature/ThingTest.php',
            'parts' => ['<?php class ThingTest {', "\n public function test_a(): void { \$this->assertTrue(true); }", "\n public function test_b(): void { \$this->assertSame(1, 1); }", "\n public function test_b(): void { }", "\n}"],
            'skip' => '$this->markTestSkipped();',
            'config' => 'phpunit.xml',
        ];

        return [
            'laravel' => [$php('{"require":{"laravel/framework":"^13"}}')],
            'codeigniter4' => [$php('{"require":{"codeigniter4/framework":"^4.5"}}')],
            'typescript' => [[
                'files' => ['package.json' => '{}', 'tsconfig.json' => '{}', 'src/thing.ts' => 'export const a = 1;'],
                'test' => 'src/thing.test.ts',
                'parts' => ['', "\ntest('a', () => { expect(1).toBe(1); });", "\ntest('b', () => { expect(2).toBe(2); });", "\ntest('b', () => {});", ''],
                'skip' => "test.skip('c', () => {});",
                'config' => 'tsconfig.json',
            ]],
            'rust' => [[
                'files' => ['Cargo.toml' => '[package]', 'rustfmt.toml' => '', 'src/lib.rs' => 'pub fn a() {}'],
                'test' => 'tests/thing.rs',
                'parts' => ['', "\n#[test]\nfn a() { assert_eq!(1, 1); }", "\n#[test]\nfn b() { assert_eq!(2, 2); }", "\n#[test]\nfn b() {}", ''],
                'skip' => '#[ignore]',
                'config' => 'rustfmt.toml',
            ]],
            'python' => [[
                'files' => ['pyproject.toml' => "[project]\nname = 'x'", 'ruff.toml' => '', 'thing.py' => 'a = 1'],
                'test' => 'tests/test_thing.py',
                'parts' => ['', "\ndef test_a():\n    assert 1 == 1\n", "\ndef test_b():\n    assert 2 == 2\n", "\ndef test_b():\n    pass\n", ''],
                'skip' => '@pytest.mark.skip',
                'config' => 'ruff.toml',
            ]],
        ];
    }

    /** @return array<string, array{array, bool}> every stack, with the change made before or after committing */
    public static function stacksAndModes(): array
    {
        $cases = [];

        foreach (self::stacks() as $name => [$stack]) {
            $cases["{$name}, uncommitted"] = [$stack, false];
            $cases["{$name}, committed"] = [$stack, true];
        }

        return $cases;
    }

    #[DataProvider('stacksAndModes')]
    public function test_a_clean_diff_passes(array $stack, bool $commit): void
    {
        $this->project($stack);

        $this->change($commit, function () use ($stack) {
            file_put_contents("{$this->repo}/notes.md", 'The agent changed something harmless.');
            $this->write($stack['test'], implode('', $stack['parts']).$stack['parts'][1]);   // more tests, more assertions
        });

        $this->assertPasses();
    }

    #[DataProvider('stacksAndModes')]
    public function test_a_deleted_test_file_fails(array $stack, bool $commit): void
    {
        $this->project($stack);

        $this->change($commit, fn () => unlink("{$this->repo}/{$stack['test']}"));

        $this->assertFails("Deleted test file {$stack['test']}");
    }

    #[DataProvider('stacksAndModes')]
    public function test_fewer_test_functions_fail(array $stack, bool $commit): void
    {
        $this->project($stack);
        [$head, $a, , , $tail] = $stack['parts'];

        $this->change($commit, fn () => $this->write($stack['test'], $head.$a.$tail));

        $this->assertFails("Fewer test functions in {$stack['test']}");
    }

    #[DataProvider('stacksAndModes')]
    public function test_fewer_assertions_fail(array $stack, bool $commit): void
    {
        $this->project($stack);
        [$head, $a, , $bWithoutAssert, $tail] = $stack['parts'];

        $this->change($commit, fn () => $this->write($stack['test'], $head.$a.$bWithoutAssert.$tail));

        $output = $this->assertFails("Fewer assertions in {$stack['test']}");
        $this->assertStringNotContainsString('Fewer test functions', $output);
    }

    #[DataProvider('stacksAndModes')]
    public function test_a_new_skip_marker_fails(array $stack, bool $commit): void
    {
        $this->project($stack);

        $this->change($commit, fn () => $this->write($stack['test'], implode('', $stack['parts'])."\n{$stack['skip']}\n"));

        $this->assertFails('New skip or suppress marker');
    }

    #[DataProvider('stacksAndModes')]
    public function test_an_edit_to_protected_config_fails(array $stack, bool $commit): void
    {
        $this->project($stack);

        $this->change($commit, fn () => $this->write($stack['config'], 'loosened'));

        $this->assertFails("Changed protected config {$stack['config']}");
    }

    public function test_a_skip_marker_in_a_new_untracked_file_fails(): void
    {
        $this->project(self::stacks()['laravel'][0]);

        $this->change(false, fn () => $this->write('app/Other.php', '<?php // @phpstan-ignore-next-line'));

        $this->assertFails('`@phpstan-ignore` in app/Other.php');
    }

    public function test_an_existing_skip_marker_is_not_new(): void
    {
        $this->project(self::stacks()['laravel'][0]);
        $this->write('app/Other.php', '<?php // @phpstan-ignore-next-line');
        $this->commitAll('add marker');

        $this->change(false, fn () => $this->write('app/Other.php', '<?php // @phpstan-ignore-next-line'."\n// more"));

        $this->assertPasses();
    }

    public function test_a_marker_quoted_in_docs_is_fine(): void
    {
        $this->project(self::stacks()['laravel'][0]);

        $this->change(false, fn () => $this->write('docs/testing.md', 'Never write `markTestSkipped`.'));

        $this->assertPasses();
    }

    public function test_a_marker_inside_a_longer_word_is_not_a_test(): void
    {
        $this->project(self::stacks()['typescript'][0]);
        $this->write('src/form.test.ts', "test('a', () => { expect(1).toBe(1); });\nsubmit();");
        $this->commitAll('add test');

        // `submit(` and `it(` are different things, so dropping `submit(` must not look like losing a test
        $this->change(false, fn () => $this->write('src/form.test.ts', "test('a', () => { expect(1).toBe(1); });"));

        $this->assertPasses();
    }

    public function test_a_changed_pyproject_tool_table_fails(): void
    {
        $this->project(['files' => ['pyproject.toml' => "[project]\nname = 'x'\n\n[tool.ruff]\nline-length = 100\n\n[tool.ruff.lint]\nselect = ['E']\n"]]);

        $this->change(false, fn () => $this->write('pyproject.toml', "[project]\nname = 'x'\n\n[tool.ruff]\nline-length = 100\n\n[tool.ruff.lint]\nselect = ['E']\nignore = ['E501']\n"));

        $this->assertFails('Changed [tool.ruff.lint] in pyproject.toml');
    }

    public function test_a_removed_pyproject_tool_table_fails(): void
    {
        $this->project(['files' => ['pyproject.toml' => "[project]\nname = 'x'\n\n[tool.mypy]\nstrict = true\n"]]);

        $this->change(false, fn () => $this->write('pyproject.toml', "[project]\nname = 'x'\n"));

        $this->assertFails('Changed [tool.mypy] in pyproject.toml');
    }

    public function test_other_pyproject_changes_pass(): void
    {
        $this->project(['files' => ['pyproject.toml' => "[project]\nname = 'x'\ndependencies = []\n\n[tool.ruff]\n# 100 is enough\nline-length = 100\n"]]);

        $this->change(false, fn () => $this->write('pyproject.toml', "[project]\nname = 'x'\ndependencies = ['requests']\n\n[tool.ruff]\nline-length = 100\n"));

        $this->assertPasses();
    }

    public function test_a_larger_phpstan_baseline_fails(): void
    {
        $baseline = fn (int ...$counts) => "parameters:\n    ignoreErrors:\n".implode('', array_map(
            fn ($count) => "        -\n            message: '#Oops#'\n            count: {$count}\n            path: app/Thing.php\n", $counts
        ));
        $this->project(['files' => ['composer.json' => '{}', 'phpstan-baseline.neon' => $baseline(2)]]);

        $this->change(false, fn () => $this->write('phpstan-baseline.neon', $baseline(2, 1)));
        $this->assertFails('baseline phpstan-baseline.neon grew from 2 to 3');

        // A bigger count on the same entry is a bigger baseline too
        $this->change(false, fn () => $this->write('phpstan-baseline.neon', $baseline(3)));
        $this->assertFails('grew from 2 to 3');

        // A smaller baseline is progress
        $this->change(false, fn () => $this->write('phpstan-baseline.neon', $baseline(1)));
        $this->assertPasses();
    }

    public function test_the_script_works_without_a_marker_file_by_applying_every_stack(): void
    {
        $this->project(['files' => ['README.md' => 'x', 'tests/a_test.py' => "def test_a():\n    assert 1\n"]]);

        $this->change(false, fn () => $this->write('tests/a_test.py', 'pass'));

        $this->assertFails('Fewer test functions in tests/a_test.py');
    }

    public function test_it_compares_against_where_the_branch_left_the_base(): void
    {
        $this->project(self::stacks()['laravel'][0]);
        $this->git('checkout', '-b', 'agent/x');

        // The base moves on, with a new test file. The branch must not look like it deleted it.
        $this->git('checkout', 'main');
        $this->write('tests/NewTest.php', '<?php class NewTest { function test_x() { $this->assertTrue(1); } }');
        $this->commitAll('base moves on');
        $this->git('checkout', 'agent/x');

        $this->assertPasses();
    }

    public function test_it_needs_a_base_it_can_find(): void
    {
        $this->project(self::stacks()['laravel'][0]);

        $process = $this->integrity(['--base=nope']);

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Could not find the base branch', $process->getErrorOutput());
    }

    public function test_it_finds_the_base_on_its_own(): void
    {
        $this->project(self::stacks()['laravel'][0]);

        $this->assertSame(0, $this->integrity([])->getExitCode());
    }

    public function test_it_needs_a_git_repository(): void
    {
        File::ensureDirectoryExists($this->repo);

        $this->assertSame(2, $this->integrity(['--base=main'])->getExitCode());
    }

    public function test_the_script_stands_alone(): void
    {
        $source = file_get_contents($this->script());

        $this->assertDoesNotMatchRegularExpression('/^\s*(use|require|include)\b/m', $source);
        $this->assertStringNotContainsString('vendor/autoload', $source);
    }

    public function test_the_rule_tables_match_the_stack_classes(): void
    {
        $rules = json_decode((new Process([PHP_BINARY, $this->script(), '--rules']))->mustRun()->getOutput(), true);
        $classes = ['php' => Php::class, 'typescript' => TypeScript::class, 'rust' => Rust::class, 'python' => Python::class];

        $this->assertSame(array_keys($classes), array_keys($rules));

        foreach ($classes as $name => $class) {
            $stack = new $class('', []);

            foreach (['protectedConfig', 'testFiles', 'testMarkers', 'assertionMarkers', 'skipMarkers'] as $table) {
                $this->assertSame($stack->{$table}(), $rules[$name][$table], "{$name} {$table} drifted from {$class}");
            }
        }
    }

    private function script(): string
    {
        return resource_path('guardrails/test-integrity.php');
    }

    /** A git repo on `main` holding the stack's files and its test file, all committed. */
    private function project(array $stack): void
    {
        File::ensureDirectoryExists($this->repo);
        $this->git('init', '-q', '-b', 'main');

        foreach ($stack['files'] as $path => $content) {
            $this->write($path, $content);
        }

        if (isset($stack['test'])) {
            $this->write($stack['test'], implode('', array_diff_key($stack['parts'], [3 => 1])));
        }

        $this->commitAll('base');
    }

    private function write(string $path, string $content): void
    {
        File::ensureDirectoryExists(dirname("{$this->repo}/{$path}"));
        file_put_contents("{$this->repo}/{$path}", $content);
    }

    /** Makes the agent's change on a branch, then commits it or leaves it in the working tree. */
    private function change(bool $commit, callable $change): void
    {
        $this->git('checkout', '-q', '-B', 'agent/x');
        $change();

        if ($commit) {
            $this->commitAll('agent');
        }
    }

    private function commitAll(string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=t@example.test', 'commit', '-q', '--allow-empty', '-m', $message);
    }

    private function git(string ...$args): void
    {
        (new Process(['git', ...$args], $this->repo))->mustRun();
    }

    private function integrity(array $args): Process
    {
        $process = new Process([PHP_BINARY, $this->script(), "--dir={$this->repo}", ...$args]);
        $process->run();

        return $process;
    }

    private function assertPasses(): void
    {
        $process = $this->integrity(['--base=main']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /** @return string What the script printed to stderr. */
    private function assertFails(string $message): string
    {
        $process = $this->integrity(['--base=main']);

        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());

        return $process->getErrorOutput();
    }
}
