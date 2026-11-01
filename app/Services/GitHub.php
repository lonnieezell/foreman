<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Setting;
use App\Support\IssueSanitiser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Everything Foreman needs from GitHub, via the gh CLI you're already
 * logged into. No tokens to configure.
 */
class GitHub
{
    /**
     * Ready issues that pass the intake gate. Issues the gate skips are saved on the
     * repository with their reasons, and the list is cleared when nothing is skipped.
     *
     * @return list<array{number:int,title:string,url:string}>
     */
    public function readyIssues(Repository $repo, int $limit): array
    {
        [$owner, $name] = explode('/', $repo->github_repo, 2);

        // Oldest first, so the queue behaves like a queue. blockedBy counts only open blockers.
        $output = $this->api($repo, ['graphql',
            '-f', 'query=query($owner: String!, $name: String!, $label: String!) { repository(owner: $owner, name: $name) {
                issues(labels: [$label], states: OPEN, first: 100, orderBy: {field: CREATED_AT, direction: ASC}) {
                    nodes { number title url lastEditedAt issueDependenciesSummary { blockedBy }
                        timelineItems(itemTypes: [LABELED_EVENT], last: 100) { nodes { ... on LabeledEvent {
                            createdAt label { name } actor { __typename login } } } } }
                } } }',
            '-F', "owner={$owner}", '-F', "name={$name}", '-f', "label={$repo->ready_label}"]);

        $writeAccess = [];
        $skipped = [];
        $ready = [];

        foreach (json_decode($output, true)['data']['repository']['issues']['nodes'] ?? [] as $issue) {
            if (count($ready) >= $limit) {
                break;
            }

            if (($issue['issueDependenciesSummary']['blockedBy'] ?? 0) !== 0) {
                continue;
            }

            if ($reason = $this->intakeSkipReason($repo, $issue, $writeAccess)) {
                $skipped[] = ['number' => $issue['number'], 'reason' => $reason];

                continue;
            }

            $ready[] = ['number' => $issue['number'], 'title' => $issue['title'], 'url' => $issue['url']];
        }

        $repo->update(['intake_skips' => $skipped ?: null]);

        return $ready;
    }

    /**
     * Why the intake gate refuses an issue, or null when it's trusted. Only a person with write
     * access counts (or a bot, if the setting allows), and only if the issue hasn't changed since the label was added.
     *
     * @param  array<string,bool>  $writeAccess  per-poll cache of who has write access
     */
    private function intakeSkipReason(Repository $repo, array $issue, array &$writeAccess): ?string
    {
        $labeled = collect($issue['timelineItems']['nodes'] ?? [])
            ->filter(fn (array $e) => ($e['label']['name'] ?? null) === $repo->ready_label)
            ->last();

        $login = $labeled['actor']['login'] ?? null;

        if (! $labeled || ! $login) {
            return 'GitHub has no record of who added the ready label';
        }

        $isBot = $labeled['actor']['__typename'] === 'Bot';

        if ($isBot && ! Setting::trustBotLabels()) {
            return "the ready label was added by a bot ({$login})";
        }

        // A trusted bot skips the access check: GitHub's collaborator API doesn't know app bots
        if (! $isBot && ! ($writeAccess[$login] ??= $this->hasWriteAccess($repo, $login))) {
            return "the ready label was added by {$login}, who doesn’t have write access";
        }

        if (($issue['lastEditedAt'] ?? null) && Carbon::parse($issue['lastEditedAt'])->gt(Carbon::parse($labeled['createdAt']))) {
            return 'the issue was edited after the ready label was added';
        }

        return null;
    }

    private function hasWriteAccess(Repository $repo, string $login): bool
    {
        $output = $this->api($repo, ["repos/{$repo->github_repo}/collaborators/{$login}/permission"], allowFailure: true);

        return in_array(json_decode($output, true)['permission'] ?? null, ['admin', 'maintain', 'write'], true);
    }

    /** Swap ready → working so the next poll can't pick it up twice. */
    public function claim(Repository $repo, int $issue): void
    {
        $this->gh($repo, ['issue', 'edit', (string) $issue,
            '--remove-label', $repo->ready_label,
            '--add-label', $repo->working_label]);
    }

    /** Used when continuing a run that stopped for a human. */
    public function reclaim(Repository $repo, int $issue): void
    {
        $this->gh($repo, ['issue', 'edit', (string) $issue,
            '--remove-label', $repo->human_label,
            '--add-label', $repo->working_label]);
    }

    public function release(Repository $repo, int $issue): void
    {
        $this->gh($repo, ['issue', 'edit', (string) $issue, '--remove-label', $repo->working_label], allowFailure: true);
    }

    public function requeue(Repository $repo, int $issue): void
    {
        $this->gh($repo, ['issue', 'edit', (string) $issue,
            '--remove-label', $repo->human_label,
            '--add-label', $repo->ready_label], allowFailure: true);
    }

    public function flagForHuman(Repository $repo, int $issue, string $comment): void
    {
        $this->gh($repo, ['issue', 'comment', (string) $issue, '--body', $comment], allowFailure: true);
        $this->gh($repo, ['issue', 'edit', (string) $issue, '--add-label', $repo->human_label], allowFailure: true);
    }

    /** The issue's title and body, cleaned and marked as untrusted data, ready for the prompt. */
    public function sanitisedIssue(Repository $repo, int $issue): string
    {
        $output = $this->gh($repo, ['issue', 'view', (string) $issue, '--json', 'title,body']);
        $data = json_decode($output, true) ?? [];

        return IssueSanitiser::wrap((string) ($data['title'] ?? ''), (string) ($data['body'] ?? ''));
    }

    /**
     * Issue comments written after $since by people with write access, cleaned and marked as untrusted.
     * Everyone else's comments are left out. Empty when there are none.
     */
    public function trustedComments(Repository $repo, int $issue, ?Carbon $since): string
    {
        [$owner, $name] = explode('/', $repo->github_repo, 2);

        $output = $this->api($repo, ['graphql',
            '-f', 'query=query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) {
                issue(number: $number) { comments(last: 100) { nodes { author { login } body createdAt } } } } }',
            '-F', "owner={$owner}", '-F', "name={$name}", '-F', "number={$issue}"]);

        $writeAccess = [];

        return collect(json_decode($output, true)['data']['repository']['issue']['comments']['nodes'] ?? [])
            ->filter(fn (array $c) => ! $since || Carbon::parse($c['createdAt'])->gt($since))
            ->filter(function (array $c) use ($repo, &$writeAccess) {
                $login = $c['author']['login'] ?? null;

                return $login && ($writeAccess[$login] ??= $this->hasWriteAccess($repo, $login));
            })
            ->map(fn (array $c) => "{$c['author']['login']} wrote:\n".IssueSanitiser::wrapText($c['body']))
            ->implode("\n\n");
    }

    /** Longest log kept per failed run: the end of it, where the failure is. */
    private const LOG_BYTES = 6000;

    /**
     * The failed-step logs of the failed checks, one entry per workflow run, trimmed to the end.
     * Checks without a workflow run (external statuses) have no log to fetch.
     *
     * @param  list<array{name:string,state:string,url:?string}>  $checks
     * @return list<array{checks:list<string>,log:string}>
     */
    public function failedCheckLogs(Repository $repo, array $checks): array
    {
        $runs = [];

        foreach ($checks as $check) {
            if ($check['state'] === 'failed' && preg_match('~/actions/runs/(\d+)~', (string) $check['url'], $match)) {
                $runs[$match[1]][] = $check['name'];
            }
        }

        return collect($runs)
            ->map(function (array $names, int|string $id) use ($repo) {
                $log = $this->gh($repo, ['run', 'view', (string) $id, '--log-failed'], allowFailure: true);

                return ['checks' => $names, 'log' => trim(mb_strcut($log, max(0, strlen($log) - self::LOG_BYTES)))];
            })
            ->filter(fn (array $run) => $run['log'] !== '')
            ->values()->all();
    }

    /** @return list<string> */
    public function labels(Repository $repo, int $issue): array
    {
        $output = $this->gh($repo, ['issue', 'view', (string) $issue, '--json', 'labels'], allowFailure: true);

        return collect(json_decode($output, true)['labels'] ?? [])->pluck('name')->all();
    }

    /** Opens a PR for a branch that is already pushed. Returns its URL. */
    public function openPullRequest(Repository $repo, string $branch, string $title, string $body): string
    {
        try {
            $output = $this->gh($repo, ['pr', 'create', '--head', $branch, '--base', $repo->base_branch, '--title', $title, '--body', $body]);
        } catch (Throwable $e) {
            // gh can fail or hang after GitHub has already opened the PR, so look before failing the run
            $existing = trim($this->gh($repo, ['pr', 'list', '--head', $branch, '--state', 'open', '--json', 'url', '--jq', '.[0].url'], allowFailure: true));

            return $existing !== '' ? $existing : throw $e;
        }

        return trim(collect(explode("\n", trim($output)))->last());
    }

    private const PULL_REQUEST_QUERY = <<<'GRAPHQL'
query($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    pullRequest(number: $number) {
      state
      headRefOid
      commits(last: 1) { nodes { commit { statusCheckRollup { contexts(first: 100) { nodes {
        __typename
        ... on CheckRun { name status conclusion detailsUrl }
        ... on StatusContext { context state targetUrl }
      } } } } } }
      reviewThreads(first: 100) { nodes {
        id isResolved path line
        comments(first: 1) { nodes { author { __typename login } body url } }
      } }
      comments(last: 50) { nodes { author { __typename } updatedAt } }
      reviews(last: 50) { nodes { author { __typename } submittedAt } }
    }
  }
}
GRAPHQL;

    /**
     * What the PR watcher needs: state, head, checks, unresolved bot threads, and when a bot last posted.
     *
     * @return array{state:string,head_sha:string,checks:list<array{name:string,state:string,url:?string}>,threads:list<array{id:string,path:?string,line:?int,author:string,body:string,url:string}>,bot_activity_at:?Carbon}
     */
    public function pullRequest(Repository $repo, string $url): array
    {
        [$owner, $name] = explode('/', $repo->github_repo, 2);

        $output = $this->api($repo, ['graphql',
            '-f', 'query='.self::PULL_REQUEST_QUERY,
            '-F', "owner={$owner}", '-F', "name={$name}", '-F', 'number='.self::prNumber($url)]);

        $pr = json_decode($output, true)['data']['repository']['pullRequest'];
        $isBot = fn (?array $author) => ($author['__typename'] ?? null) === 'Bot';

        $checks = collect($pr['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] ?? [])
            ->map(fn (array $c) => $c['__typename'] === 'CheckRun'
                ? ['name' => $c['name'], 'url' => $c['detailsUrl'] ?? null, 'state' => match (true) {
                    $c['status'] !== 'COMPLETED' => 'pending',
                    in_array($c['conclusion'], ['FAILURE', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE'], true) => 'failed',
                    default => 'passed',
                }]
                : ['name' => $c['context'], 'url' => $c['targetUrl'] ?? null, 'state' => match ($c['state']) {
                    'PENDING', 'EXPECTED' => 'pending',
                    'FAILURE', 'ERROR' => 'failed',
                    default => 'passed',
                }])
            ->values()->all();

        $threads = collect($pr['reviewThreads']['nodes'])
            ->filter(fn (array $t) => ! $t['isResolved'] && $isBot($t['comments']['nodes'][0]['author'] ?? null))
            ->map(fn (array $t) => [
                'id' => $t['id'],
                'path' => $t['path'],
                'line' => $t['line'],
                'author' => $t['comments']['nodes'][0]['author']['login'],
                'body' => $t['comments']['nodes'][0]['body'],
                'url' => $t['comments']['nodes'][0]['url'],
            ])
            ->values()->all();

        $botActivity = collect($pr['comments']['nodes'])->filter(fn ($c) => $isBot($c['author']))->pluck('updatedAt')
            ->merge(collect($pr['reviews']['nodes'])->filter(fn ($r) => $isBot($r['author']))->pluck('submittedAt'))
            ->filter()
            ->max();

        return [
            'state' => $pr['state'],
            'head_sha' => $pr['headRefOid'],
            'checks' => $checks,
            'threads' => $threads,
            'bot_activity_at' => $botActivity ? Carbon::parse($botActivity) : null,
        ];
    }

    public function replyToThread(Repository $repo, string $threadId, string $body): void
    {
        $this->api($repo, ['graphql',
            '-f', 'query=mutation($id: ID!, $body: String!) { addPullRequestReviewThreadReply(input: {pullRequestReviewThreadId: $id, body: $body}) { comment { id } } }',
            '-f', "id={$threadId}", '-f', "body={$body}"], allowFailure: true);
    }

    public function resolveThread(Repository $repo, string $threadId): void
    {
        $this->api($repo, ['graphql',
            '-f', 'query=mutation($id: ID!) { resolveReviewThread(input: {threadId: $id}) { thread { id } } }',
            '-f', "id={$threadId}"], allowFailure: true);
    }

    public function commentOnPullRequest(Repository $repo, string $url, string $body): void
    {
        $this->gh($repo, ['pr', 'comment', (string) self::prNumber($url), '--body', $body], allowFailure: true);
    }

    /** Creates the label when the repo lacks it, then adds it to the PR. A failure leaves the PR as it is. */
    public function labelPullRequest(Repository $repo, string $url, string $label): void
    {
        $this->gh($repo, ['label', 'create', $label, '--color', 'C5DEF5'], allowFailure: true);   // fails harmlessly when it exists
        $this->gh($repo, ['pr', 'edit', (string) self::prNumber($url), '--add-label', $label], allowFailure: true);
    }

    public function addHumanLabel(Repository $repo, int $issue): void
    {
        $this->gh($repo, ['issue', 'edit', (string) $issue, '--add-label', $repo->human_label], allowFailure: true);
    }

    private static function prNumber(string $url): int
    {
        return (int) str($url)->rtrim('/')->afterLast('/')->toString();
    }

    /** Create the three labels if they don't exist yet (--force makes it idempotent). */
    public function ensureLabels(Repository $repo): void
    {
        $labels = [
            [$repo->ready_label, '2E6B5E', 'Spec is ready for the agent'],
            [$repo->working_label, 'D99A00', 'Agent is working on this'],
            [$repo->human_label, 'B23A2B', 'Agent stopped and needs a human'],
        ];

        foreach ($labels as [$name, $color, $description]) {
            $this->gh($repo, ['label', 'create', $name, '--color', $color, '--description', $description, '--force']);
        }
    }

    /** Reads owner/name from a local checkout's remote. */
    public function detectRepo(string $localPath): ?string
    {
        $result = Process::path($localPath)->run(['gh', 'repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner']);

        if ($result->failed()) {
            Log::warning('gh repo view failed', ['path' => $localPath, 'exit' => $result->exitCode(), 'error' => trim($result->errorOutput())]);
        }

        return $result->successful() ? trim($result->output()) : null;
    }

    private function gh(Repository $repo, array $args, bool $allowFailure = false): string
    {
        $result = Process::path($repo->local_path)
            ->timeout(60)
            ->run(['gh', ...$args, '--repo', $repo->github_repo]);

        if (! $allowFailure) {
            $result->throw();
        }

        return $result->output();
    }

    /** `gh api` takes no --repo flag. */
    private function api(Repository $repo, array $args, bool $allowFailure = false): string
    {
        $result = Process::path($repo->local_path)->timeout(60)->run(['gh', 'api', ...$args]);

        if (! $allowFailure) {
            $result->throw();
        }

        return $result->output();
    }
}
