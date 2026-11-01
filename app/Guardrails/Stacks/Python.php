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

    public function verifyCommands(): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $run = $this->has($dir, 'uv.lock') ? 'uv run ' : ($this->has($dir, 'poetry.lock') ? 'poetry run ' : '');
            $steps = ['ruff format --check', 'ruff check'];

            if ($this->configures($dir, 'mypy', ['mypy.ini'])) {
                $steps[] = 'mypy .';
            }
            if ($this->configures($dir, 'pyright', ['pyrightconfig.json'])) {
                $steps[] = 'pyright';
            }
            $steps[] = 'pytest';

            foreach ($steps as $step) {
                $commands[] = $this->inDir($dir, $run.$step);
            }
        }

        return $commands;
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
