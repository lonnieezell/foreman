<?php

namespace App\Guardrails\Stacks;

use App\Guardrails\Stack;

class Php extends Stack
{
    public const LARAVEL = 'laravel';

    public const CODEIGNITER4 = 'codeigniter4';

    public function name(): string
    {
        return 'php';
    }

    /** The framework named in composer.json requires, or null for plain PHP. */
    public function framework(): ?string
    {
        $requires = $this->requires();

        return match (true) {
            isset($requires['codeigniter4/framework']) => self::CODEIGNITER4,
            isset($requires['laravel/framework']) => self::LARAVEL,
            default => null,
        };
    }

    public function hasShield(): bool
    {
        return isset($this->requires()['codeigniter4/shield']);
    }

    public function protectedConfig(): array
    {
        return ['phpunit.xml*', 'phpstan*.neon', 'rector.php', 'pint.json'];
    }

    public function lockFiles(): array
    {
        return ['composer.lock'];
    }

    public function testFiles(): array
    {
        return ['tests/**'];
    }

    public function testMarkers(): array
    {
        return ['test(', 'it(', 'function test'];
    }

    public function assertionMarkers(): array
    {
        return ['expect(', 'assert'];
    }

    public function skipMarkers(): array
    {
        return ['markTestSkipped', 'markTestIncomplete', '->skip(', '->todo(', '@pest-mutate-ignore', '@phpstan-ignore'];
    }

    public function formatter(): ?string
    {
        $requires = $this->requires();

        return match (true) {
            isset($requires['laravel/pint']) => 'vendor/bin/pint',
            isset($requires['friendsofphp/php-cs-fixer']) => 'vendor/bin/php-cs-fixer fix',
            default => null,
        };
    }

    public function verifyCommands(): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $requires = $this->requires($dir);

            if (isset($requires['laravel/pint'])) {
                $commands[] = $this->inDir($dir, 'vendor/bin/pint --test');
            } elseif (isset($requires['friendsofphp/php-cs-fixer'])) {
                $commands[] = $this->inDir($dir, 'vendor/bin/php-cs-fixer fix --dry-run --diff');
            }
            if (isset($requires['phpstan/phpstan'])) {
                $commands[] = $this->inDir($dir, 'vendor/bin/phpstan analyse');
            }
            $commands[] = $this->inDir($dir, isset($requires['pestphp/pest']) ? 'vendor/bin/pest' : 'vendor/bin/phpunit');
        }

        return $commands;
    }

    public function tier3Paths(): array
    {
        $paths = match ($this->framework()) {
            self::LARAVEL => [
                'app/Policies/**', 'app/Http/Middleware/**', 'routes/**', 'config/auth.php', 'config/sanctum.php',
                'database/migrations/**',
            ],
            self::CODEIGNITER4 => [
                'app/Config/Filters.php', 'app/Config/Routes.php', 'app/Config/Security.php', 'app/Config/Cors.php',
                'app/Config/Encryption.php', 'app/Filters/**', 'app/Database/Migrations/**',
            ],
            default => [],
        };

        return $this->hasShield()
            ? [...$paths, 'app/Config/Auth.php', 'app/Config/AuthGroups.php', 'app/Config/AuthToken.php', 'app/Config/AuthJWT.php']
            : $paths;
    }

    public function riskyCode(): array
    {
        $patterns = match ($this->framework()) {
            self::LARAVEL => [
                '/\bDB::raw\(/', '/->(whereRaw|selectRaw|orderByRaw|havingRaw)\(/', '/\bunguard\(/',
                '/\$request->all\(\)/', '/\bGate::define\(/',
            ],
            self::CODEIGNITER4 => [
                // `->query(` is risky when the SQL is a string with a variable in it
                '/->query\(\s*"[^"]*\$/', '/->query\(\s*\'[^\']*\'\s*\.\s*\$/',
                '/->protect\(\s*false\s*\)/',
                // request input passed straight to a model
                '/[mM]odel->\w+\([^;]*->get(Post|Var)\(/',
                '/->setFilter\(/', '/[\'"]except[\'"]\s*=>/',
            ],
            default => [],
        };

        return $this->hasShield()
            ? [...$patterns, '/\b(addPermission|addGroup|syncGroups|loginById)\(/']
            : $patterns;
    }

    /** Composer requires and dev requires: for one folder, or for all of them. */
    private function requires(?string $dir = null): array
    {
        $requires = [];

        foreach ($dir === null ? $this->dirs : [$dir] as $d) {
            $composer = $this->json($d, 'composer.json');
            $requires += ($composer['require'] ?? []) + ($composer['require-dev'] ?? []);
        }

        return $requires;
    }
}
