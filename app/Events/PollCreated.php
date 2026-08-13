<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A question was put to a channel.
 *
 * Fired after the poll and its options are committed and the message is in the
 * conversation, so anything acting on it finds a poll that is complete and
 * already visible — a workflow that answered "er staat een nieuwe vraag" before
 * the options existed would be describing half a poll.
 */
class PollCreated
{
    use Dispatchable;

    public function __construct(public readonly string $pollId) {}
}
