<?php

namespace App\Workflows;

use Carbon\CarbonInterface;
use Exception;

/**
 * A step saying "not now, come back at this moment".
 *
 * An exception rather than a return value, and that is a deliberate choice: it
 * means the runner's ordinary path — resolve, run, record, carry on — needs no
 * branch for waiting, and no action other than the one that waits has to know
 * that pausing exists at all.
 *
 * Caught before the general Throwable in the runner, because everything about
 * what happens next is different: the run is not failed, the step is not
 * recorded as broken, and the position is kept so the resumer knows where to
 * pick up.
 */
class WorkflowPaused extends Exception
{
    /**
     * Typed as the interface rather than as Carbon, because this application
     * runs on CarbonImmutable — see AppServiceProvider. A concrete Carbon here
     * turns every delay into a TypeError, which the runner would faithfully
     * record as a failed step.
     */
    public function __construct(public readonly CarbonInterface $until)
    {
        parent::__construct('De workflow wacht.');
    }
}
