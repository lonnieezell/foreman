<?php

namespace App\Enums;

/** How the agent says a run ended, in its result file. */
enum Outcome: string
{
    /** Open a PR with the agent's title and body. */
    case Pr = 'pr';

    /** The agent needs a human; it gives a comment and a reason. */
    case NeedsHuman = 'needs_human';

    /** The issue is already done; the agent gives a comment. */
    case AlreadyDone = 'already_done';
}
