<?php

namespace App\Services;

use App\Enums\Outcome;
use Illuminate\Support\Facades\File;

/** The files the agent leaves in its worktree for Foreman to act on. */
final class AgentReport
{
    /** Where the agent says how it ended, relative to the worktree. */
    public const RESULT_FILE = '.foreman/result.json';

    /** Where a review round writes its verdicts, relative to the worktree. */
    public const REVIEW_FILE = '.foreman/review.json';

    public function __construct(private string $worktree) {}

    /** The agent's outcome, or null when the result file is missing, broken, or lacks what its outcome needs. */
    public function outcome(): ?AgentOutcome
    {
        $result = $this->read(self::RESULT_FILE);
        $text = fn (string $key) => is_string($result[$key] ?? null) && trim($result[$key]) !== '';

        return match (Outcome::tryFrom(is_string($result['outcome'] ?? null) ? $result['outcome'] : '')) {
            Outcome::Pr => $text('title') && $text('body') ? new AgentOutcome(Outcome::Pr, title: $result['title'], body: $result['body']) : null,
            Outcome::NeedsHuman => $text('comment') && $text('reason') ? new AgentOutcome(Outcome::NeedsHuman, comment: $result['comment'], reason: $result['reason']) : null,
            Outcome::AlreadyDone => $text('comment') ? new AgentOutcome(Outcome::AlreadyDone, comment: $result['comment']) : null,
            null => null,
        };
    }

    /** @return array<string,array{thread_id:string,verdict:string,reply?:string}> thread id => verdict; anything unreadable is left out */
    public function verdicts(): array
    {
        return collect($this->read(self::REVIEW_FILE))
            ->filter(fn ($e) => is_array($e) && is_string($e['thread_id'] ?? null) && is_string($e['verdict'] ?? null))
            ->keyBy('thread_id')
            ->all();
    }

    /** Remove the last run's result and verdicts, so a stale one is never acted on. */
    public function clear(): void
    {
        File::delete(["{$this->worktree}/".self::RESULT_FILE, "{$this->worktree}/".self::REVIEW_FILE]);
    }

    /** The file's JSON, or an empty array when it is missing or isn't a JSON object or list. */
    private function read(string $file): array
    {
        $json = json_decode((string) @file_get_contents("{$this->worktree}/{$file}"), true);

        return is_array($json) ? $json : [];
    }
}
