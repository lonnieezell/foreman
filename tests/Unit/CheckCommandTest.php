<?php

namespace Tests\Unit;

use App\Jobs\ProcessRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CheckCommandTest extends TestCase
{
    public static function checkCommands(): array
    {
        return array_map(fn ($c) => [$c], [
            './vendor/bin/pest',
            './vendor/bin/phpunit',
            './vendor/bin/phpstan analyse',
            './vendor/bin/pint',
            'php artisan test',
            'composer test',
            'npm run lint',
            'pnpm test',
            'yarn typecheck',
            'bun run check',
            'npm run test:unit',
            'npx tsc --noEmit',
            'npx vitest run',
            'npx jest',
            'npx eslint .',
            'npx prettier --check .',
            'npx biome check',
            'npx playwright test',
            'cargo test',
            'cargo +nightly fmt',
            'cargo clippy -- -D warnings',
            'cargo nextest run',
            'cd src-tauri && cargo check',
            'rustfmt src/main.rs',
            'pytest',
            'pytest -x tests/test_api.py',
            'python -m pytest',
            'ruff check .',
            'ruff format --check',
            'mypy .',
            'pyright',
            'uv run pytest',
            'uv run ruff check',
            'uv run mypy src',
            'poetry run pytest',
            'poetry run pyright',
            'cd api && uv run pytest',
        ]);
    }

    public static function otherCommands(): array
    {
        return array_map(fn ($c) => [$c], [
            'ls tests',
            'npm install',
            'npm run dev',
            'cargo build',
            'cargo run',
            'python main.py',
            'uv run python main.py',
            'uv sync',
            'poetry install',
            'git status',
        ]);
    }

    #[DataProvider('checkCommands')]
    public function test_check_commands_are_detected(string $command): void
    {
        $this->assertMatchesRegularExpression(ProcessRun::CHECK_COMMAND, $command);
    }

    #[DataProvider('otherCommands')]
    public function test_other_commands_are_not_detected(string $command): void
    {
        $this->assertDoesNotMatchRegularExpression(ProcessRun::CHECK_COMMAND, $command);
    }
}
