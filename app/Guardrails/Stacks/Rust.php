<?php

namespace App\Guardrails\Stacks;

use App\Guardrails\Stack;

class Rust extends Stack
{
    public function name(): string
    {
        return 'rust';
    }

    public function protectedConfig(): array
    {
        return [
            'rustfmt.toml', '.rustfmt.toml', 'clippy.toml', '.clippy.toml', 'deny.toml', '.cargo/config.toml',
            'rust-toolchain*', 'src-tauri/capabilities/**',
        ];
    }

    public function networkHosts(): array
    {
        return ['crates.io', 'index.crates.io', 'static.crates.io'];
    }

    public function lockFiles(): array
    {
        return ['Cargo.lock'];
    }

    public function testFiles(): array
    {
        // Inline `#[cfg(test)]` modules live in source files, so the integrity check reads them from `testMarkers()`.
        return ['tests/**'];
    }

    public function testMarkers(): array
    {
        return ['#[test]', '#[tokio::test]'];
    }

    public function assertionMarkers(): array
    {
        return ['assert!', 'assert_eq!', 'assert_ne!', '#[should_panic]'];
    }

    public function skipMarkers(): array
    {
        return ['#[ignore]', '#[allow(clippy::', '#[allow(dead_code)]'];
    }

    public function formatter(): ?string
    {
        return 'rustfmt';
    }

    public function formatExtensions(): array
    {
        return ['rs'];
    }

    public function verifyCommands(string $base): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $commands[] = $this->inDir($dir, 'cargo fmt --check');
            $commands[] = $this->inDir($dir, 'cargo clippy --all-targets -- -D warnings');
            $commands[] = $this->inDir($dir, 'cargo test');
        }

        return $commands;
    }

    public function ciSteps(): array
    {
        [$tool, $name, $command] = $this->has($this->ciDir(), 'deny.toml')
            ? ['cargo-deny', 'Cargo deny', 'cargo deny check']
            : ['cargo-audit', 'Cargo audit', 'cargo audit'];

        return [
            'setup' => [
                ['name' => 'Set up Rust', 'uses' => 'dtolnay/rust-toolchain@stable', 'with' => ['components' => 'rustfmt, clippy']],
                ['name' => 'Cache Rust build', 'uses' => 'Swatinem/rust-cache@v2'] + ($this->ciDir() === '' ? [] : ['with' => ['workspaces' => $this->ciDir()]]),
            ],
            'lint' => [
                ['name' => 'Format check', 'run' => 'cargo fmt --check'],
                ['name' => 'Clippy', 'run' => 'cargo clippy --all-targets -- -D warnings'],
            ],
            'test' => [
                ['name' => 'Tests', 'run' => 'cargo test'],
            ],
            'audit' => [
                ['name' => "Install {$tool}", 'uses' => 'taiki-e/install-action@v2', 'with' => ['tool' => $tool]],
                ['name' => $name, 'run' => $command],
                ...$this->codeqlOrSemgrep('rust', 'p/default'),
            ],
        ];
    }

    public function tier3Paths(): array
    {
        return ['src-tauri/tauri.conf.json', 'src-tauri/capabilities/**', '**/auth*'];
    }

    public function riskyCode(): array
    {
        return ['/\bunsafe\s/', '/\bstd::process::Command\b/', '/\btransmute\b/'];
    }
}
