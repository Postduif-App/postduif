<?php

namespace App\Events;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody put an emoji on a message.
 *
 * Beside ReactionToggled rather than instead of it, because the two answer
 * different questions. ReactionToggled carries the whole reaction set, which is
 * what a browser needs and says nothing about what changed; this one names the
 * one emoji and the one person, which is what a trigger needs and would be
 * useless to a browser.
 *
 * Only the putting on. Taking a reaction off is not something anybody wants a
 * workflow behind — and if it were, it would be its own trigger rather than a
 * flag on this one.
 */
class ReactionAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Message $message,
        public readonly User $user,
        public readonly string $emoji,
    ) {}
}
