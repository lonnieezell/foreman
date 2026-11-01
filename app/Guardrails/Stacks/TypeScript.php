<?php

namespace App\Guardrails\Stacks;

use App\Guardrails\Stack;

class TypeScript extends Stack
{
    private const LOCK_FILES = ['package-lock.json' => 'npm', 'pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'bun.lock' => 'bun', 'bun.lockb' => 'bun'];

    public function name(): string
    {
        return 'typescript';
    }

    public function protectedConfig(): array
    {
        return [
            'tsconfig*.json', 'eslint.config.*', '.eslintrc*', 'vitest.config.*', 'jest.config.*',
            'playwright.config.*', 'biome.json', 'biome.jsonc', '.prettierrc*',
        ];
    }

    public function networkHosts(): array
    {
        return ['registry.npmjs.org', 'registry.yarnpkg.com'];
    }

    public function lockFiles(): array
    {
        return array_keys(self::LOCK_FILES);
    }

    public function testFiles(): array
    {
        return ['*.test.*', '*.spec.*', '__tests__/**', 'e2e/**'];
    }

    public function testMarkers(): array
    {
        return ['it(', 'test('];
    }

    public function assertionMarkers(): array
    {
        return ['expect('];
    }

    public function skipMarkers(): array
    {
        return ['.skip(', '.only(', 'xit(', 'xdescribe(', '.todo(', '@ts-ignore', '@ts-expect-error', 'eslint-disable'];
    }

    public function formatter(): ?string
    {
        foreach ($this->dirs as $dir) {
            if ($this->has($dir, 'biome.json') || $this->has($dir, 'biome.jsonc') || $this->hasDependency($dir, '@biomejs/biome')) {
                return 'npx biome format --write';
            }
            if ($this->hasDependency($dir, 'prettier')) {
                return 'npx prettier --write';
            }
        }

        return null;
    }

    public function formatExtensions(): array
    {
        return ['ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs', 'json', 'css'];
    }

    public function verifyCommands(string $base): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $scripts = $this->json($dir, 'package.json')['scripts'] ?? [];
            $pm = $this->packageManager($dir);

            if (isset($scripts['lint'])) {
                $commands[] = $this->inDir($dir, "{$pm} run lint");
            }
            if (isset($scripts['typecheck'])) {
                $commands[] = $this->inDir($dir, "{$pm} run typecheck");
            } elseif ($this->has($dir, 'tsconfig.json')) {
                $commands[] = $this->inDir($dir, 'npx tsc --noEmit');
            }
            if (isset($scripts['test'])) {
                $commands[] = $this->inDir($dir, "{$pm} run test");
            }
        }

        return $commands;
    }

    public function tier3Paths(): array
    {
        return ['middleware.ts', '**/auth/**', 'app/api/**', 'pages/api/**'];
    }

    public function riskyCode(): array
    {
        return ['/\bdangerouslySetInnerHTML\b/', '/\beval\(/', '/\bchild_process\b/', '/\binnerHTML\b/'];
    }

    /** The package manager, read from the lock file. npm when there is none. */
    private function packageManager(string $dir): string
    {
        foreach (self::LOCK_FILES as $file => $pm) {
            if ($this->has($dir, $file)) {
                return $pm;
            }
        }

        return 'npm';
    }

    private function hasDependency(string $dir, string $package): bool
    {
        $json = $this->json($dir, 'package.json');

        return isset($json['dependencies'][$package]) || isset($json['devDependencies'][$package]);
    }
}
