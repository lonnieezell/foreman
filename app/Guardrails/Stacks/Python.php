<?php

namespace App\Guardrails\Stacks;

use App\Guardrails\Stack;

class Python extends Stack
{
    /** pytest exits 5 when it collects no tests, which a project without tests yet shouldn't fail on. */
    private const PYTEST = 'pytest || [ $? -eq 5 ]';

    public function name(): string
    {
        return 'python';
    }

    public function protectedConfig(): array
    {
        // Edits to `[tool.*]` tables in pyproject.toml are caught by the integrity check, not by path.
        return ['ruff.toml', '.ruff.toml', 'mypy.ini', 'pytest.ini', 'tox.ini', 'setup.cfg'];
    }

    public function networkHosts(): array
    {
        return ['pypi.org', 'files.pythonhosted.org'];
    }

    public function lockFiles(): array
    {
        return ['uv.lock', 'poetry.lock'];
    }

    public function testFiles(): array
    {
        return ['tests/**', 'test_*.py', '*_test.py', 'conftest.py'];
    }

    public function testMarkers(): array
    {
        return ['def test_'];
    }

    public function assertionMarkers(): array
    {
        return ['assert ', 'self.assert', 'pytest.raises'];
    }

    public function skipMarkers(): array
    {
        return [
            'pytest.mark.skip', 'pytest.mark.skipif', 'pytest.mark.xfail', 'unittest.skip', 'pytest.skip(',
            '# type: ignore', '# noqa',
        ];
    }

    public function formatter(): ?string
    {
        foreach ($this->dirs as $dir) {
            if ($this->usesRuff($dir)) {
                return 'ruff format';
            }
        }

        return null;
    }

    public function formatExtensions(): array
    {
        return ['py'];
    }

    public function verifyCommands(string $base): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            if ($this->usesRuff($dir)) {
                // Explicit files skip ruff's excludes unless `--force-exclude` is set
                $commands[] = $this->inDir($dir, $this->onChangedFiles($base, $this->run($dir, 'ruff', 'ruff format --check --force-exclude')));
                $commands[] = $this->inDir($dir, $this->onChangedFiles($base, $this->run($dir, 'ruff', 'ruff check --force-exclude')));
            }
            foreach ($this->typeCheckers($dir) as $tool => $command) {
                $commands[] = $this->inDir($dir, $this->run($dir, $tool, $command));
            }
            if ($this->usesPytest($dir)) {
                $commands[] = $this->inDir($dir, $this->run($dir, 'pytest', self::PYTEST));
            }
        }

        return $commands;
    }

    public function ciSteps(): array
    {
        $dir = $this->ciDir();
        $uv = $this->has($dir, 'uv.lock');
        $poetry = $this->has($dir, 'poetry.lock');
        $types = $this->typeCheckers($dir);
        $ruff = $this->usesRuff($dir);
        $pytest = $this->usesPytest($dir);
        // The project may not list these tools itself, so each install path brings them
        $tools = [...($ruff ? ['ruff'] : []), ...($pytest ? ['pytest'] : []), ...array_keys($types), 'pip-audit'];
        $run = fn (string $tool, string $command) => $this->run($dir, $tool, $command);

        // setup-python fails when caching is on and it finds no requirements file
        $requirements = collect(['requirements.txt', 'pyproject.toml'])->first(fn (string $file) => $this->has($dir, $file));
        $pipCache = $uv || $poetry || $requirements === null ? [] : ['cache' => 'pip', 'cache-dependency-path' => $this->ciPath($requirements)];

        $install = match (true) {
            $uv => 'uv sync',
            $poetry => 'pipx install poetry && poetry install && poetry run pip install '.implode(' ', $tools),
            default => 'pip install '.implode(' ', [$this->has($dir, 'requirements.txt') ? '-r requirements.txt' : '-e .', ...$tools]),
        };

        return [
            'setup' => [
                ['name' => 'Set up Python', 'uses' => 'actions/setup-python@v5', 'with' => ['python-version' => '3.12', ...$pipCache]],
                ...($uv ? [['name' => 'Set up uv', 'uses' => 'astral-sh/setup-uv@v5', 'with' => ['enable-cache' => true]]] : []),
                // setup-python's own Poetry cache needs Poetry installed first, and it is installed below
                ...($poetry ? [['name' => 'Cache Poetry', 'uses' => 'actions/cache@v4', 'with' => ['path' => '~/.cache/pypoetry', 'key' => "poetry-\${{ runner.os }}-\${{ hashFiles('{$this->ciPath('poetry.lock')}') }}"]]] : []),
                ['name' => 'Install dependencies', 'run' => $install],
            ],
            'lint' => [
                ...($ruff ? [
                    ['name' => 'Ruff format check', 'run' => $run('ruff', 'ruff format --check')],
                    ['name' => 'Ruff check', 'run' => $run('ruff', 'ruff check')],
                ] : []),
                ...array_map(fn (string $tool, string $command) => ['name' => ucfirst($tool), 'run' => $run($tool, $command)], array_keys($types), $types),
            ],
            'test' => $pytest ? [['name' => 'Tests', 'run' => $run('pytest', self::PYTEST)]] : [],
            'audit' => [
                ['name' => 'Dependency audit', 'run' => $run('pip-audit', 'pip-audit')],
                ...$this->codeqlOrSemgrep('python', 'p/python'),
            ],
        ];
    }

    /** `$command` run with the folder's tool runner. uv adds `$tool` for the run, since the project may not list it. */
    private function run(string $dir, string $tool, string $command): string
    {
        return match (true) {
            $this->has($dir, 'uv.lock') => "uv run --with {$tool} {$command}",
            $this->has($dir, 'poetry.lock') => "poetry run {$command}",
            default => $command,
        };
    }

    private function usesRuff(string $dir): bool
    {
        return $this->configures($dir, 'ruff', ['ruff.toml', '.ruff.toml']) || $this->declares($dir, 'ruff');
    }

    private function usesPytest(string $dir): bool
    {
        return $this->configures($dir, 'pytest', ['pytest.ini', 'conftest.py']) || $this->declares($dir, 'pytest');
    }

    /** True when pyproject.toml or a requirements file lists `$tool` as a dependency, in any of their formats. */
    private function declares(string $dir, string $tool): bool
    {
        $files = ['pyproject.toml', ...array_map('basename', glob($this->path($dir, 'requirements*.txt')) ?: [])];
        // `"ruff>=0.5"` in a list, `ruff = "^0.5"` in a Poetry table, or `ruff==0.6.0` on a requirements line
        $pattern = '/(^|["\'\s])'.preg_quote($tool, '/').'\s*([<>=~!\[;,"\']|$)/m';

        foreach ($files as $file) {
            if (preg_match($pattern, $this->read($dir, $file))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> The type checkers a folder configures, as tool => command. */
    private function typeCheckers(string $dir): array
    {
        return array_filter([
            'mypy' => $this->configures($dir, 'mypy', ['mypy.ini']) ? 'mypy .' : null,
            'pyright' => $this->configures($dir, 'pyright', ['pyrightconfig.json']) ? 'pyright' : null,
        ]);
    }

    public function tier3Paths(): array
    {
        return ['settings.py', '**/middleware*.py', '**/permissions.py', '**/migrations/**', '**/auth/**'];
    }

    public function riskyCode(): array
    {
        return [
            '/\beval\(/', '/\bexec\(/', '/\bpickle\b/', '/\bshell\s*=\s*True\b/', '/\.raw\(/', '/\.extra\(/',
            '/\byaml\.load\(/',
        ];
    }

    /** True when the folder has one of `$files` or a `[tool.<tool>]` table in pyproject.toml. */
    private function configures(string $dir, string $tool, array $files): bool
    {
        foreach ($files as $file) {
            if ($this->has($dir, $file)) {
                return true;
            }
        }

        return str_contains($this->read($dir, 'pyproject.toml'), "[tool.{$tool}");
    }
}
