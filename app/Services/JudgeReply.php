<?php

namespace App\Services;

/** The agent's answer to a scope judge veto: a claim, and line ranges on the base branch that back it up. */
final class JudgeReply
{
    /** @param  list<array{path:string,start:int,end:int}>  $evidence */
    public function __construct(
        public readonly string $claim,
        public readonly array $evidence = [],
    ) {}

    /**
     * The evidence as Foreman reads it from the base branch, never as the agent quoted it.
     * `code` is null when the base lacks those lines. A range past the end of the file ends where the file does.
     *
     * @return list<array{path:string,start:int,end:int,code:?string}>
     */
    public function excerpts(Worktree $worktree): array
    {
        return array_map(function (array $e) use ($worktree) {
            $lines = $worktree->linesAtBase($e['path'], $e['start'], $e['end']);

            return $lines === null
                ? [...$e, 'code' => null]
                : [...$e, 'end' => $e['start'] + count($lines) - 1, 'code' => implode("\n", $lines)];
        }, $this->evidence);
    }
}
