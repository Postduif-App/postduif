<?php

namespace App\Notifications\Messages;

/**
 * What a notification wants to say over Pushover.
 *
 * A small object rather than an array, so a notification that forgets the title
 * fails where it is written instead of arriving at Pushover as a push with no
 * heading.
 */
class PushoverMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $urlTitle = null,
    ) {}
}
