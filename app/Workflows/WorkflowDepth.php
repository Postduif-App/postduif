<?php

namespace App\Workflows;

use Closure;

/**
 * How deep inside a workflow we currently are.
 *
 * A workflow posts a message; the message trips a keyword trigger; that
 * workflow posts a message. Nothing about either one is wrong, and together
 * they never stop. Since neither can see the other, the only thing that knows
 * is the process running them both — which is what this counts.
 *
 * A depth rather than a flat "no workflows inside workflows", because chaining
 * is a reasonable thing to want: a workflow that files a ticket and a second
 * one that announces new tickets is two workflows doing their own job. What is
 * not reasonable is the fourth one, by which point nobody is describing a chain
 * any more.
 *
 * In-process state, and it holds because the whole chain runs in one place: the
 * runner calls SendMessage in the same process, and the listener that would
 * start the next workflow runs inside that call. A run that reaches the queue
 * starts a fresh process at depth zero, which is why the count is carried in
 * the run's context as well — see the runner.
 */
class WorkflowDepth
{
    /**
     * Three, and the number is a judgement rather than a limit anything
     * technical imposes. Two workflows in a chain is a design; four is a loop
     * somebody has not noticed yet.
     */
    public const MAX = 3;

    private static int $depth = 0;

    /**
     * Run something one level deeper, and come back up whatever happens.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(int $depth, Closure $callback): mixed
    {
        $previous = self::$depth;
        self::$depth = $depth;

        try {
            return $callback();
        } finally {
            self::$depth = $previous;
        }
    }

    public static function current(): int
    {
        return self::$depth;
    }

    /**
     * Whether a workflow started from here would still be a chain rather than a
     * loop.
     */
    public static function hasRoom(): bool
    {
        return self::$depth < self::MAX;
    }

    /** For a test that wants to start from nothing. */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}
