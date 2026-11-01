# Setting up CI in your repo

Foreman's [guardrails](guardrails.md) run on your machine, while the agent works. CI is the second line. It runs the same kind of checks on every pull request, whoever wrote it. Foreman can write that CI setup into your repository for you.

## How to run it

From Foreman's folder, point the command at the local checkout of the repository. A path inside the checkout works too. The files always go at the repository's root, because GitHub only reads workflows there.

```bash
php artisan foreman:install-ci ../path/to/your-repo
```

It finds the stacks with the same marker files as [Stack rules](stack-rules.md#stack-rules). Then it does four things:

1. Creates a new branch, `foreman/ci-kit`, from whatever is checked out. Check out your default branch first, or the CI PR carries that branch's commits too. Use `--branch=name` to pick another name.
2. Writes `.github/workflows/foreman-ci.yml`.
3. Copies the [test-integrity script](guardrails.md#test-integrity) to `.github/foreman/test-integrity.php`.
4. Commits both files to that branch.

**It never pushes.** Review the commit, then push the branch and open the PR yourself. The command stops with an error, and changes nothing, if the path is not a git checkout, if no stack is found, if `foreman-ci.yml` already exists, or if the branch can't be created, for example because it already exists. If the commit itself fails, for example because a pre-commit hook rejects it, the command removes the files and the branch, and switches back.

## What each job checks

The workflow has one job per stack it found. The steps run top to bottom. A step that fails stops its job.

| Stack | Steps, in order |
| --- | --- |
| PHP | Formatter check (Pint or PHP CS Fixer), Rector dry run (only if the repo has Rector), PHPStan (only if present), integrity, tests (Pest or PHPUnit), `composer audit`, Semgrep with the PHP rules, which include the Laravel ones. A Laravel app with a `.env.example` gets a copy of it as `.env` and a fresh app key before the checks run. A Laravel app with a Vite config and a `build` script also sets up Node and builds its assets before the tests, because pages that use `@vite` fail without the built manifest. |
| TypeScript/React | Lint (if there is a `lint` script), typecheck (if there is a `typecheck` script, or a `tsconfig.json` with `typescript` installed), integrity, tests (if there is a `test` script other than npm's placeholder), the package manager's `audit`, CodeQL. |
| Rust | `cargo fmt --check`, `cargo clippy -D warnings`, integrity, tests, `cargo deny check` (if there is a `deny.toml`) or `cargo audit`, CodeQL. |
| Python | `ruff format --check` and `ruff check` (if the project uses ruff), mypy and pyright (each if configured), integrity, pytest (if the project uses it), `pip-audit`, CodeQL. A tool counts when it has a config or is listed as a dependency. The job installs those tools itself, in case the project configures one without listing it. pytest finding no tests passes. |

Each job also runs a copy of the test-integrity script. The script needs PHP, so every job, whatever its stack, installs a pinned PHP version (8.3). A runner image update can't change it. The check compares the pull request with its base branch, so the jobs fetch the full git history. The job runs the base branch's copy of the script, not the pull request's. So a pull request can't weaken the check that judges it. Only the pull request that first adds the script uses its own copy.

CodeQL on a private repository needs GitHub Advanced Security. The TypeScript, Rust and Python jobs run CodeQL only on public repos. On a private repo they run Semgrep instead, with rules for the stack. The PHP job always runs Semgrep, because CodeQL doesn't support PHP. If your private repo has Advanced Security, delete the `if` lines on the CodeQL and Semgrep steps. The workflow file says so too.

Each job caches its dependencies between runs, so only the first run downloads everything:

- **PHP:** `ramsey/composer-install` runs `composer install` and caches the downloads.
- **TypeScript:** `actions/setup-node` caches by the lock file. Bun has no built-in cache, so Bun jobs install from scratch. A repo with no lock file gets no cache.
- **Rust:** `Swatinem/rust-cache` caches the cargo registry and the build output.
- **Python:** `actions/setup-python` caches pip by `requirements.txt`, or by `pyproject.toml` when there is none. uv uses its own cache. Poetry gets an `actions/cache` step keyed on `poetry.lock`.

A stack in a sub-folder, such as a Tauri app's `src-tauri`, runs in that folder. If a stack sits in several folders, only the shallowest one gets checks.

## Why the order fails fast

The steps go from cheapest and most likely to fail to the most expensive. A formatting slip fails in seconds. Static analysis takes longer. Tests take longer still. Audits and code scanning go last, because they need the network or a long scan. If the cheap step fails, you get the answer in under a minute, and you don't spend runner time on the slow steps. The integrity check sits before the tests on purpose. It reads the diff in a second, and it tells you that a test was deleted or skipped before you wait on a test run that might pass because of it.

## Make the checks required

Writing the workflow is not enough. **Without branch protection, nothing stops a merge.** A red check is only a warning. Anyone with write access can merge past it, and so can an agent or a hurried reviewer. Make each check required:

1. In GitHub, open the repository's **Settings**, then **Branches**.
2. Add a branch protection rule, or a ruleset, for your default branch.
3. Turn on **Require status checks to pass before merging**.
4. Search for each job by name (`php`, `typescript`, `rust`, `python`) and add it. A job appears in the search only after it has run once, so push the branch and let the workflow run first.
5. Turn on **Require branches to be up to date before merging**, so the checks run against the latest base.

## What it doesn't do

- It does not push, open the PR or change branch protection. Those are yours to do.
- It does not read your CI secrets or tune the tools. A repo with its own Rector, PHPStan or ESLint rules keeps them.
- It only adds a step when it can see the tool in the repo. A tool that is installed some other way is skipped.
- It can't confirm the workflow passes on your repo. Read the first run, and expect to adjust install steps for unusual setups.
