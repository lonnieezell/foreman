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

    public function verifyCommands(): array
    {
        $commands = [];

        foreach ($this->dirs as $dir) {
            $commands[] = $this->inDir($dir, 'cargo fmt --check');
            $commands[] = $this->inDir($dir, 'cargo clippy --all-targets -- -D warnings');
            $commands[] = $this->inDir($dir, 'cargo test');
        }

        return $commands;
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
