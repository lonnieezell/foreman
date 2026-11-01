#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Foreman's test-integrity check.
 *
 * One file, PHP 8.1+, no Composer and no Foreman code, so a repository can copy it into its own CI.
 * It compares the working tree (committed, staged, unstaged and untracked changes) with the merge-base
 * of HEAD and a base branch, and fails on:
 *   - a deleted test file
 *   - fewer test functions, or fewer assertions, in a test file
 *   - a new skip or suppress marker
 *   - an edit to protected tool config, including `[tool.*]` tables in pyproject.toml
 *   - a PHPStan baseline that holds more errors than before
 *
 * Usage: php test-integrity.php [--base=origin/main] [--dir=.] [--rules]
 * Exit:  0 clean, 1 violations found, 2 could not run. `--rules` prints the built-in rule tables as JSON.
 *
 * The rule tables below mirror app/Guardrails/Stacks/*. A test fails when they drift apart.
 */

const STACKS = [
    'php' => [
        'markers' => ['composer.json'],
        'protectedConfig' => ['phpunit.xml*', 'phpstan*.neon', 'rector.php', 'pint.json'],
        'testFiles' => ['tests/**'],
        'inlineTestFiles' => [],
        'testMarkers' => ['test(', 'it(', 'function test'],
        'assertionMarkers' => ['expect(', 'assert'],
        'skipMarkers' => ['markTestSkipped', 'markTestIncomplete', '->skip(', '->todo(', '@pest-mutate-ignore', '@phpstan-ignore'],
    ],
    'typescript' => [
        'markers' => ['package.json'],
        'protectedConfig' => [
            'tsconfig*.json', 'eslint.config.*', '.eslintrc*', 'vitest.config.*', 'jest.config.*',
            'playwright.config.*', 'biome.json', 'biome.jsonc', '.prettierrc*',
        ],
        'testFiles' => ['*.test.*', '*.spec.*', '__tests__/**', 'e2e/**'],
        'inlineTestFiles' => [],
        'testMarkers' => ['it(', 'test('],
        'assertionMarkers' => ['expect('],
        'skipMarkers' => ['.skip(', '.only(', 'xit(', 'xdescribe(', '.todo(', '@ts-ignore', '@ts-expect-error', 'eslint-disable'],
    ],
    'rust' => [
        'markers' => ['Cargo.toml'],
        'protectedConfig' => [
            'rustfmt.toml', '.rustfmt.toml', 'clippy.toml', '.clippy.toml', 'deny.toml', '.cargo/config.toml',
            'rust-toolchain*', 'src-tauri/capabilities/**',
        ],
        'testFiles' => ['tests/**'],
        // Unit tests sit in source files, so only a drop in their count can be caught there
        'inlineTestFiles' => ['*.rs'],
        'testMarkers' => ['#[test]', '#[tokio::test]'],
        'assertionMarkers' => ['assert!', 'assert_eq!', 'assert_ne!', '#[should_panic]'],
        'skipMarkers' => ['#[ignore]', '#[allow(clippy::', '#[allow(dead_code)]'],
    ],
    'python' => [
        'markers' => ['pyproject.toml', 'requirements*.txt', 'setup.py'],
        'protectedConfig' => ['ruff.toml', '.ruff.toml', 'mypy.ini', 'pytest.ini', 'tox.ini', 'setup.cfg'],
        'testFiles' => ['tests/**', 'test_*.py', '*_test.py', 'conftest.py'],
        'inlineTestFiles' => [],
        'testMarkers' => ['def test_'],
        'assertionMarkers' => ['assert ', 'self.assert', 'pytest.raises'],
        'skipMarkers' => [
            'pytest.mark.skip', 'pytest.mark.skipif', 'pytest.mark.xfail', 'unittest.skip', 'pytest.skip(',
            '# type: ignore', '# noqa',
        ],
    ],
];

/** Folders that hold other people's code. Dot folders are skipped too. */
const SKIPPED_DIRS = ['vendor', 'node_modules', 'target', 'venv', '__pycache__'];

/** Prose and data files, and this script's own copy in a target repo. A skip marker quoted in one is not a skipped test. */
const SKIP_MARKER_EXEMPT = '/\.(md|mdx|markdown|txt|rst|lock|json)$|^\.github\/foreman\/test-integrity\.php$/i';

const BASE_CANDIDATES = ['origin/HEAD', 'origin/main', 'origin/master', 'main', 'master'];

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}

function main(array $args): int
{
    $options = [];

    foreach ($args as $arg) {
        if ($arg === '--rules') {
            echo json_encode(STACKS, JSON_PRETTY_PRINT), "\n";

            return 0;
        }

        if (! preg_match('/^--(base|dir)=(.+)$/', $arg, $m)) {
            return fail("Unknown argument: {$arg}\nUsage: php test-integrity.php [--base=origin/main] [--dir=.] [--rules]");
        }

        $options[$m[1]] = $m[2];
    }

    $dir = (string) realpath($options['dir'] ?? '.');

    if ($dir === '' || git($dir, ['rev-parse', '--git-dir'])[0] !== 0) {
        return fail('This is not a git repository.');
    }

    $base = resolveBase($dir, $options['base'] ?? null);

    if ($base === null) {
        return fail('Could not find the base branch. Pass it with --base=origin/main.');
    }

    $changes = changes($dir, $base);
    $stacks = detectStacks($dir);
    $violations = [];

    foreach ($changes as $path => [$status, $before, $after]) {
        array_push($violations, ...checkFile($stacks, $path, $status, $before, $after));
    }

    if ($violations) {
        fwrite(STDERR, "Test integrity check failed:\n- ".implode("\n- ", $violations)."\n");

        return 1;
    }

    echo 'Test integrity check passed (', count($changes), " changed files).\n";

    return 0;
}

function fail(string $message): int
{
    fwrite(STDERR, $message."\n");

    return 2;
}

/** @return array{int, string} exit code and stdout */
function git(string $dir, array $args): array
{
    $process = proc_open(['git', '-C', $dir, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        return [1, ''];
    }

    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out];
}

/** The commit to compare against: where the branch left its base, so a base that moved on doesn't count. */
function resolveBase(string $dir, ?string $given): ?string
{
    foreach ($given === null ? BASE_CANDIDATES : [$given] as $ref) {
        if (git($dir, ['rev-parse', '--verify', '--quiet', "{$ref}^{commit}"])[0] !== 0) {
            continue;
        }

        [$code, $mergeBase] = git($dir, ['merge-base', 'HEAD', $ref]);

        return $code === 0 ? trim($mergeBase) : $ref;
    }

    return null;
}

/** @return array<string, array{string, ?string, ?string}> path => status (A, M or D), content at base, content now */
function changes(string $dir, string $base): array
{
    $changes = [];
    $parts = explode("\0", rtrim(git($dir, ['diff', '--name-status', '--no-renames', '-z', $base])[1], "\0"));

    for ($i = 0; $i + 1 < count($parts); $i += 2) {
        $changes[$parts[$i + 1]] = $parts[$i][0];
    }

    foreach (explode("\0", rtrim(git($dir, ['ls-files', '--others', '--exclude-standard', '-z'])[1], "\0")) as $path) {
        if ($path !== '') {
            $changes[$path] = 'A';
        }
    }

    $result = [];

    foreach ($changes as $path => $status) {
        $before = $status === 'A' ? null : git($dir, ['show', "{$base}:{$path}"])[1];
        $after = is_file("{$dir}/{$path}") ? (string) file_get_contents("{$dir}/{$path}") : null;

        // Binary files hold no tests or markers
        if (str_contains((string) $before, "\0") || str_contains((string) $after, "\0")) {
            continue;
        }

        $result[$path] = [$status, $before, $after];
    }

    return $result;
}

/** The stacks with a marker file in the repository. When none is found, all of them apply. */
function detectStacks(string $dir): array
{
    $files = explode("\0", rtrim(git($dir, ['ls-files', '--cached', '--others', '--exclude-standard', '-z'])[1], "\0"));
    $found = [];

    foreach ($files as $file) {
        $folders = array_slice(explode('/', $file), 0, -1);

        if (array_filter($folders, fn ($f) => in_array($f, SKIPPED_DIRS, true) || str_starts_with($f, '.'))) {
            continue;
        }

        foreach (STACKS as $name => $stack) {
            foreach ($stack['markers'] as $marker) {
                if (fnmatch($marker, basename($file))) {
                    $found[$name] = $stack;
                }
            }
        }
    }

    return $found ?: STACKS;
}

/** The union of one rule table across the detected stacks. */
function rules(array $stacks, string $table): array
{
    return array_values(array_unique(array_merge(...array_column($stacks, $table))));
}

/** @return list<string> */
function checkFile(array $stacks, string $path, string $status, ?string $before, ?string $after): array
{
    $violations = [];
    $isBaseline = (bool) preg_match('/^phpstan.*baseline.*\.neon$|^baseline\.neon$/', basename($path));
    $isTest = matches(rules($stacks, 'testFiles'), $path);

    if ($status === 'D' && $isTest) {
        return ["Deleted test file {$path}."];
    }

    if (! $isBaseline && matches(rules($stacks, 'protectedConfig'), $path)) {
        $violations[] = "Changed protected config {$path}. Change the code to satisfy the tool, not the tool's settings.";
    }

    if ($isBaseline && baselineSize((string) $after) > baselineSize((string) $before)) {
        $violations[] = "The PHPStan baseline {$path} grew from ".baselineSize((string) $before).' to '.baselineSize((string) $after).' errors. Fix the errors instead of baselining them.';
    }

    if (basename($path) === 'pyproject.toml') {
        foreach (changedToolTables((string) $before, (string) $after) as $table) {
            $violations[] = "Changed [{$table}] in {$path}. Change the code to satisfy the tool, not the tool's settings.";
        }
    }

    if ($isTest || matches(rules($stacks, 'inlineTestFiles'), $path)) {
        foreach (['testMarkers' => 'test functions', 'assertionMarkers' => 'assertions'] as $table => $label) {
            $markers = rules($stacks, $table);

            if (($now = countMarkers((string) $after, $markers)) < ($was = countMarkers((string) $before, $markers))) {
                $violations[] = "Fewer {$label} in {$path}: {$was} before, {$now} now.";
            }
        }
    }

    if ($after !== null && ! preg_match(SKIP_MARKER_EXEMPT, $path)) {
        foreach (rules($stacks, 'skipMarkers') as $marker) {
            if (countMarkers($after, [$marker]) > countMarkers((string) $before, [$marker])) {
                $violations[] = "New skip or suppress marker `{$marker}` in {$path}. Fix the code or the test instead of silencing it.";
            }
        }
    }

    return $violations;
}

/** Same glob rules as the guard hook: a pattern may match at the root or after any folder. */
function matches(array $patterns, string $path): bool
{
    foreach ($patterns as $pattern) {
        $regex = strtr(preg_quote($pattern, '#'), ['\*\*/' => '(?:.*/)?', '\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']);

        if (preg_match("#(?:^|/){$regex}$#", $path)) {
            return true;
        }
    }

    return false;
}

/** Total occurrences of the markers. One that starts with a word character must not sit inside a longer word. */
function countMarkers(string $text, array $markers): int
{
    $count = 0;

    foreach ($markers as $marker) {
        $start = preg_match('/^\w/', $marker) ? '(?<!\w)' : '';
        $count += (int) preg_match_all('/'.$start.preg_quote($marker, '/').'/', $text);
    }

    return $count;
}

/** The errors a PHPStan baseline holds: one per `message:` entry, times its `count:`. */
function baselineSize(string $neon): int
{
    $size = 0;
    $current = 0;

    foreach (preg_split('/\R/', $neon) as $line) {
        if (preg_match('/^\s*(?:-\s+)?message:/', $line)) {
            $size += $current = 1;
        } elseif ($current && preg_match('/^\s*count:\s*(\d+)/', $line, $m)) {
            $size += (int) $m[1] - $current;
            $current = 0;
        }
    }

    return $size;
}

/** @return list<string> Names of `tool.*` tables that differ between two pyproject.toml files. */
function changedToolTables(string $before, string $after): array
{
    [$old, $new] = [toolTables($before), toolTables($after)];
    $names = array_unique([...array_keys($old), ...array_keys($new)]);

    return array_values(array_filter($names, fn ($name) => ($old[$name] ?? null) !== ($new[$name] ?? null)));
}

/** @return array<string, string> table name => its lines, without comments or blank lines */
function toolTables(string $toml): array
{
    $tables = [];
    $name = null;

    foreach (preg_split('/\R/', $toml) as $line) {
        $line = trim($line);

        if (preg_match('/^\[\[?\s*(tool(?:\.[^\]\s]+)*)\s*\]\]?$/', $line, $m)) {
            $name = $m[1];
            $tables[$name] = ($tables[$name] ?? '').'#';
        } elseif (str_starts_with($line, '[')) {
            $name = null;
        } elseif ($name !== null && $line !== '' && ! str_starts_with($line, '#')) {
            $tables[$name] .= "\n{$line}";
        }
    }

    return $tables;
}
