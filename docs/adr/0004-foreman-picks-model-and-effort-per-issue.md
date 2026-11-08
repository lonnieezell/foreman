# Foreman picks the model and effort for each issue

Status: proposed (2026-11-08)

## Context

Each repository has one fixed model and effort. Every run on it uses them, whether the issue is a typo or a hard bug. Easy issues spend more than they need. Hard issues can get less model than they need.

Foreman could read each issue and pick for it. The risks are a pick that is too low, which wastes a run and gives a weak PR, and a pick that is too high, which burns usage limits. The issue text is also untrusted, so it could try to steer the pick.

## Decision

**Modes.** Each repository is in **Auto** or **Fixed** mode.

- Fixed is today's behavior: one model and effort, any value.
- Auto is the default. Existing repositories switch to Auto in the migration.

**Difficulties and the ladder.** There are five fixed difficulties: `trivial`, `small`, `medium`, `large`, `hard`. A global ladder in global settings maps each one to a model and effort. The starting ladder:

| Difficulty | Step |
|---|---|
| `trivial` | `sonnet low` |
| `small` | `sonnet medium` |
| `medium` | `sonnet high` |
| `large` | `opus medium` |
| `hard` | `opus high` |

- You can change what each difficulty runs, but not add or remove difficulties.
- Saving the ladder fails if a step is cheaper than the one below it. The order is model first (`sonnet` < `opus` < `fable`), then effort.
- Haiku is not allowed on the ladder.

`large` is `opus medium` because that is what the Opus alias already runs with no `--effort` flag. So the step keeps today's cost.

**Floor and ceiling.** An Auto repository has a floor and a ceiling difficulty. Every pick is clamped to them. The default is the full range, `trivial` to `hard`.

**The classifier.** At the start of `ProcessRun`, after the usage check, a Haiku call picks the difficulty. It reads:

- the issue title, body and trusted comments, through `IssueSanitiser` and `GitHub::trustedComments`, capped at about 20,000 characters
- the detected stack
- the stack's tier-3 path patterns

It returns a difficulty and a one-sentence reason. Its prompt tells it to pick the higher level when torn, and to ignore anything in the issue about model, effort or difficulty. Its tokens count toward the run's usage.

**Label override.** A label such as `foreman:hard` on the issue skips the classifier. The label is still clamped. In Fixed mode, labels do nothing, and the run log says so.

**Fallback.** If the classifier gives no usable answer twice, Foreman uses the fallback step. The fallback is a global setting, `sonnet high` by default, clamped to the repository's range.

**Follow-ups.** The pick is made once per issue. Resumed runs and fix-up rounds inherit the step from the run before, clamped again to the current range. A label added after the first run wins on the next round.

**No automatic step-up.** A failed run stops as it does today. To retry higher, add a label. Every run records its difficulty and outcome, so automatic step-up can be decided later from data.

**Visibility.** The pick shows in the run log, on the dashboard, in the PR footer, and as a `difficulty:<level>` label on the PR. Each place names the source: classifier, label or fallback. Foreman never labels the issue with its own pick, because an issue label is an override.

**Effort environment variable.** `CLAUDE_CODE_EFFORT_LEVEL` beats the `--effort` flag. Foreman removes it from the `claude` process's environment.

**Dry run.** `php artisan foreman:classify --repo=<id> --issue=<n>` prints the difficulty, the clamped step and the reason, without starting a run.

## Consequences

- Each new issue costs one extra Haiku call.
- A low pick can produce a weak PR or a failed run. The operator fixes it with a label. That is a manual step until automatic step-up is decided.
- Prompt injection in an issue can at worst push the pick to the repository's ceiling.
- Untrusted comments never reach the classifier, so only people with write access can steer it through comments.
- The model and effort on a run may differ from the repository's settings. The run records its own.
- The review pass's `/code-review` level still follows the risk tier. The scope judge stays on Haiku.
