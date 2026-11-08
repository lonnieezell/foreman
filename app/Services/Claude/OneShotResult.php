<?php

namespace App\Services\Claude;

/** What a one-shot call answered. */
final class OneShotResult
{
    /**
     * @param  array|null  $structured  The answer that followed the schema, if there was one.
     * @param  string  $output  What Claude printed, for messages about an answer that didn't fit.
     * @param  string|null  $error  Why the call failed, or null when Claude exited cleanly.
     * @param  list<array>  $rateLimits  Each `rate_limit_info` Claude reported.
     * @param  array  $usage  What the call spent, keyed by the Run's usage columns, as `AgentResult::usage()` gives it.
     */
    public function __construct(
        public readonly ?array $structured = null,
        public readonly string $output = '',
        public readonly ?string $error = null,
        public readonly array $rateLimits = [],
        public readonly array $usage = [],
    ) {}
}
