# Repository Guidelines

## Project Structure & Module Organization

Foreman is a Laravel 13 + Livewire 4 app that turns labelled GitHub issues into PRs by running `claude -p` in git worktrees.

- `foreman:poll` (`app/Console/Commands/PollRepositories.php`) runs every minute from `routes/console.php`. It only calls `gh`: it claims ready issues, reaps runs with stale heartbeats, and checks watched PRs via `app/Services/PrWatcher.php`.
- `app/Jobs/ProcessRun.php` runs on the `runs` queue. It creates the worktree, runs the setup script, streams Claude's `stream-json` into the run log, and then settles the run by reading GitHub, never the agent's claims.
- Run states and dashboard stations live in `app/Enums/RunStatus.php`.
- All GitHub access goes through `app/Services/GitHub.php`, which uses the `gh` CLI. Foreman has no API tokens.
- Agent prompts are templates in `resources/prompts/` with `{{placeholders}}`.
- `app/Guardrails/` holds per-stack rules (PHP, TypeScript, Rust, Python), detected by `StackSet`.
- User docs live in `docs/*.md` and are served in-app. A new page must be added to `App\Livewire\Docs::PAGES`, which is the allowlist. Pages link to each other as `slug.md`.
- Runtime files go in `storage/foreman/worktrees/` and `storage/foreman/logs/run-<id>.log` (plus `.jsonl`).

## Build, Test, and Development Commands

- `composer run setup`: install dependencies, create `.env`, migrate, and build assets.
- `composer run dev`: server, queue listener, Pail logs and Vite.
- `composer run foreman`: web on port 8787, the scheduler, and three `runs` workers.
- `composer test`: the full suite. For a single test, use `php artisan test --filter=StackDetectionTest`.
- `php artisan foreman:poll --repo=<id> --force`: one poll by hand.
- `./vendor/bin/pint`: format PHP.

Queue workers keep old code until they restart.

## Coding Style & Naming Conventions

- Format PHP with Laravel Pint (default preset, no `pint.json`).
- `.editorconfig`: 4-space indent, LF line endings; YAML uses 2 spaces.

## Testing Guidelines

- Tests use PHPUnit 12 classes (not Pest), in `tests/Feature` and `tests/Unit`.
- They run on in-memory SQLite with a `sync` queue.
- Shell calls (`gh`, `git`, `claude`) are faked with `Process::fake()`. Follow `tests/Feature/RunEndingsTest.php` and `tests/Feature/PrWatchTest.php`.

## Commit & Pull Request Guidelines

- Use conventional commits with the issue number as the scope, for example `feat(#13): detect JS/TS and Rust check commands`. Use `style(#13):` for Pint-only commits. Work with no issue uses a topic scope, such as `feat(dashboard):`.
- Branches are `issue-<n>`, or `agent/issue-<n>` when Foreman opened them.
- PR bodies start with `Closes #<n>`.
- Docs changes ship in the same PR as the code they describe.
