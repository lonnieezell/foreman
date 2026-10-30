<?php

namespace App\Services;

use App\Models\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Everything the factory needs from GitHub, via the gh CLI you're already
 * logged into. No tokens to configure.
 */
class GitHub
{
    /** @return list<array{number:int,title:string,url:string}> */
    public function readyIssues(Repository $repo, int $limit): array
    {
        $output = $this->gh($repo, [
            'issue', 'list',
            '--label', $repo->ready_label,
            '--state', 'open',
            '--limit', (string) $limit,
            '--json', 'number,title,url',
        ]);

        // Oldest first, so the queue behaves like a queue
        return array_reverse(json_decode($output, true) ?? []);
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

    /** @return list<string> */
    public function labels(Repository $repo, int $issue): array
    {
        $output = $this->gh($repo, ['issue', 'view', (string) $issue, '--json', 'labels'], allowFailure: true);

        return collect(json_decode($output, true)['labels'] ?? [])->pluck('name')->all();
    }

    public function pullRequestFor(Repository $repo, string $branch): ?string
    {
        $output = $this->gh($repo, ['pr', 'list', '--head', $branch, '--state', 'all', '--json', 'url', '--limit', '1'], allowFailure: true);

        return json_decode($output, true)[0]['url'] ?? null;
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
}
