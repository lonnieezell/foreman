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
            foreach ($this->checks($dir) as $command) {
                $commands[] = $this->inDir($dir, $command);
            }
        }

        return $commands;
    }

    /** @return array<string, string> The lint, typecheck and test commands a folder has, as step name => command. */
    private function checks(string $dir): array
    {
        $scripts = $this->json($dir, 'package.json')['scripts'] ?? [];
        $pm = $this->packageManager($dir);

        return array_filter([
            'Lint' => isset($scripts['lint']) ? "{$pm} run lint" : null,
            'Typecheck' => isset($scripts['typecheck']) ? "{$pm} run typecheck" : ($this->has($dir, 'tsconfig.json') ? 'npx tsc --noEmit' : null),
            'Tests' => isset($scripts['test']) ? "{$pm} run test" : null,
        ]);
    }

    public function ciSteps(): array
    {
        $dir = $this->ciDir();
        $package = $this->json($dir, 'package.json');
        $pm = $this->packageManager($dir);
        // Yarn 2 and later, marked by .yarnrc.yml, renamed `--frozen-lockfile` and dropped `yarn audit`
        $berry = $pm === 'yarn' && $this->has($dir, '.yarnrc.yml');
        $steps = [];

        foreach ($this->checks($dir) as $name => $command) {
            $steps[$name === 'Tests' ? 'test' : 'lint'][] = ['name' => $name, 'run' => $command];
        }

        $lock = collect(self::LOCK_FILES)->keys()->first(fn (string $file) => $this->has($dir, $file));
        // setup-node fails when caching is on and it finds no lock file
        $cache = $lock === null ? [] : ['cache' => $pm, 'cache-dependency-path' => $this->ciPath($lock)];
        $node = ['name' => 'Set up Node', 'uses' => 'actions/setup-node@v4', 'with' => ['node-version' => 'lts/*', ...$cache]];
        $setup = match ($pm) {
            // pnpm/action-setup fails without a version unless package.json names one in `packageManager`
            'pnpm' => [['name' => 'Set up pnpm', 'uses' => 'pnpm/action-setup@v4'] + (isset($package['packageManager']) ? [] : ['with' => ['version' => 'latest']]), $node, ['name' => 'Install dependencies', 'run' => 'pnpm install --frozen-lockfile']],
            'yarn' => [$node, ['name' => 'Install dependencies', 'run' => $berry ? 'yarn install --immutable' : 'yarn install --frozen-lockfile']],
            'bun' => [['name' => 'Set up Bun', 'uses' => 'oven-sh/setup-bun@v2'], ['name' => 'Install dependencies', 'run' => 'bun install --frozen-lockfile']],
            default => [$node, ['name' => 'Install dependencies', 'run' => 'npm ci']],
        };

        return [
            'setup' => $setup,
            'lint' => $steps['lint'] ?? [],
            'test' => $steps['test'] ?? [],
            'audit' => [
                ['name' => 'Dependency audit', 'run' => $berry ? 'yarn npm audit' : "{$pm} audit"],
                ...$this->codeqlOrSemgrep('javascript-typescript', 'p/typescript', 'p/react'),
            ],
        ];
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
