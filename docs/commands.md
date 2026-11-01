# Commands

Every command you might run by hand. Run them from Foreman's folder.

## Composer scripts

| Command | What it does |
| --- | --- |
| `composer run setup` | First install. Installs PHP and npm dependencies, creates `.env` and an app key, runs the migrations (SQLite by default), and builds the assets. |
| `composer run foreman` | Runs Foreman. Starts the web UI at <http://localhost:8787>, the scheduler, and three run workers, all in one terminal. Stop it with Ctrl+C. |
| `composer run update-app` | After pulling new changes. Installs new dependencies, runs new migrations and rebuilds the assets. Restart `composer run foreman` afterwards, because the workers keep the old code until they restart. |
| `composer run dev` | For working on Foreman's UI. Starts a web server on port 8000, a queue listener, Pail logs and Vite with hot reload. It has no scheduler, and its listener doesn't take the `runs` queue, so no runs happen. |
| `composer test` | Runs the test suite. |

## Artisan commands

### foreman:poll

```bash
php artisan foreman:poll [--repo=<id>] [--force]
```

One check, the same as the scheduler runs every minute. It reaps dead runs, watches PRs, then looks for ready issues in each started repository that is due.

| Option | Meaning |
| --- | --- |
| `--repo=<id>` | Only check this repository. |
| `--force` | Ignore the repository's poll interval. |

### foreman:install-ci

```bash
php artisan foreman:install-ci <path> [--branch=foreman/ci-kit]
```

Writes Foreman's CI workflow into the repository at `<path>`, on a new branch, and commits it. It never pushes. See [Setting up CI in your repo](ci-setup.md).

| Option | Meaning |
| --- | --- |
| `<path>` | The local checkout, or any folder inside it. |
| `--branch=<name>` | The new branch to commit to. Default: `foreman/ci-kit`. |

### Hook commands

Claude Code runs these during a run. You don't run them yourself. Each takes the path of the run's rules file in `storage/foreman/hooks/`.

| Command | Hook | What it does |
| --- | --- | --- |
| `foreman:guard` | `PreToolUse` | Blocks protected edits and commands. See [Protected files and commands](guardrails.md#protected-files-and-commands). |
| `foreman:format-staged` | `PreToolUse` | Formats staged files before a `git commit`. See [Formatting at commit time](guardrails.md#formatting-at-commit-time). |
| `foreman:stop` | `Stop` | Runs the checks before the agent may stop. See [Definition of done](guardrails.md#definition-of-done). |

## Extra workers

`composer run foreman` starts three workers. To run more issues at once, start another in a second terminal:

```bash
php artisan queue:work --queue=runs --timeout=0 --tries=1
```

See [Runs stay on Waiting](troubleshooting.md#runs-stay-on-waiting).

A run can take hours, so the queue gives each job 48 hours before it treats the job as lost. If you set `DB_QUEUE_RETRY_AFTER` in `.env`, keep it longer than your longest run. Otherwise a second worker picks up a run that is still going.

## The test-integrity script

```bash
php resources/guardrails/test-integrity.php [--base=origin/main] [--dir=.] [--rules]
```

Fails when tests are deleted, weakened or skipped, or when tool settings are loosened. It has no dependencies, so you can copy it into any repository. See [Test integrity](guardrails.md#test-integrity).

| Option | Meaning |
| --- | --- |
| `--base=<ref>` | What to compare with. Without it, the script tries `origin/HEAD`, `origin/main`, `origin/master`, `main` and `master`. |
| `--dir=<path>` | The repository to check. Default: the current folder. |
| `--rules` | Print the built-in rule tables as JSON, and exit. |

It exits 0 when clean, 1 when it finds a violation, and 2 when it can't run.
