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
        public readonly ?JudgeReply $judgeReply = null,
    ) {}
}
