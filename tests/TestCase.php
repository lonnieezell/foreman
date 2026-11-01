<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** What a faked scope judge answers when it passes the change. */
    protected const SCOPE_JUDGE_PASS = '{"type":"result","is_error":false,"structured_output":{"in_scope":true,"weakens_tests":false,"reason":"Stays within the issue."}}';
}
