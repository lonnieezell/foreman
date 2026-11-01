<?php

namespace App\Guardrails\Stacks;

use App\Guardrails\Stack;

class Python extends Stack
{
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
        return 'ruff format';
    }

    public function formatExtensions(): array
    {
        return ['py'];
    }

    public function verifyCommands(string $base): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $run = $this->has($dir, 'uv.lock') ? 'uv run ' : ($this->has($dir, 'poetry.lock') ? 'poetry run ' : '');
            // Explicit files skip ruff's excludes unless `--force-exclude` is set
            $commands[] = $this->inDir($dir, $this->onChangedFiles($base, "{$run}ruff format --check --force-exclude"));
            $commands[] = $this->inDir($dir, $this->onChangedFiles($base, "{$run}ruff check --force-exclude"));
            foreach ([...array_values($this->typeCheckers($dir)), 'pytest'] as $step) {
                $commands[] = $this->inDir($dir, $run.$step);
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
        // The project may not list these tools itself, so each install path brings them
        $tools = ['ruff', 'pytest', ...array_keys($types), 'pip-audit'];
        $run = fn (string $tool, string $command) => match (true) {
            $uv => "uv run --with {$tool} {$command}",
            $poetry => "poetry run {$command}",
            default => $command,
        };

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
                ['name' => 'Ruff format check', 'run' => $run('ruff', 'ruff format --check')],
                ['name' => 'Ruff check', 'run' => $run('ruff', 'ruff check')],
                ...array_map(fn (string $tool, string $command) => ['name' => ucfirst($tool), 'run' => $run($tool, $command)], array_keys($types), $types),
            ],
            'test' => [
                ['name' => 'Tests', 'run' => $run('pytest', 'pytest')],
            ],
            'audit' => [
                ['name' => 'Dependency audit', 'run' => $run('pip-audit', 'pip-audit')],
                ...$this->codeqlOrSemgrep('python', 'p/python'),
            ],
        ];
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
