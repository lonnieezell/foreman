<?php

namespace Tests\Unit;

use Tests\TestCase;

class QueueConfigTest extends TestCase
{
    public function test_a_run_is_never_handed_to_a_second_worker_while_it_is_still_running(): void
    {
        // A run can start the agent three times (work, review pass, scope correction), each for up to
        // max_minutes (600 at most), plus the setup script and gate. Past retry_after, another worker reserves the job.
        $longestRun = 3 * 600 * 60 + 3600;

        $this->assertGreaterThan($longestRun, config('queue.connections.database.retry_after'));
    }
}
