<?php

namespace App\Enums;

/** How the agent says a run ended, in its result file. */
enum Outcome: string
{
    /** Open a PR with the agent's title and body. */
    case Pr = 'pr';

    /** The agent needs a human; it gives a comment and a reason. */
    case NeedsHuman = 'needs_human';

    /** The agent asks the operator something it can't decide alone; it gives the questions. Answered in Foreman. */
    case Question = 'question';

    /** The issue is already done; the agent gives a comment. */
    case AlreadyDone = 'already_done';
}
