<?php

namespace App\Services;

use App\Enums\Outcome;

/** What the agent wrote in its result file. Only the fields its kind needs are set. */
final class AgentOutcome
{
    public function __construct(
        public readonly Outcome $kind,
        public readonly ?string $title = null,
        public readonly ?string $body = null,
        public readonly ?string $comment = null,
        public readonly ?string $reason = null,
        public readonly ?string $judgeClaim = null,
        /** @var list<array{path:string,start:int,end:int}> Line ranges on the base branch that back up the claim. */
        public readonly array $judgeEvidence = [],
    ) {}
}
