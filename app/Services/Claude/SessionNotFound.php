<?php

namespace App\Services\Claude;

use RuntimeException;

/**
 * Claude found no conversation for the session it was asked to resume: the profile changed, the session files are gone,
 * or the worktree path differs. Only a conflict fix retries without the session; everywhere else it is a normal failure.
 */
class SessionNotFound extends RuntimeException {}
