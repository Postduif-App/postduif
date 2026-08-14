<?php

namespace App\Notifications\Messages;

/**
 * What a notification wants to say in a browser's own notification tray.
 *
 * A small object rather than an array, so a notification that forgets the title
 * fails where it is written instead of arriving at the browser as a bubble with
 * no heading.
 *
 * Deliberately poor. Everything here leaves our servers encrypted, but it is
 * decrypted and stored by a push service we do not run and that mostly is not in
 * the EU, so this object is the boundary of what we are willing to hand over:
 * enough for a member to recognise what happened and click through, and nothing
 * that reads as content. A tray bubble is a summons, not a copy of the message —
 * the message itself is behind the click, on our own domain, behind a login.
 */
class WebPushMessage
{
    /**
     * @param  string|null  $url  Where the click lands. Read by the service worker
     *                            and opened, so it is a path or URL in this
     *                            application and never anywhere else.
     * @param  string|null  $tag  Groups bubbles in the browser's tray. A second
     *                            notification with the same tag replaces the
     *                            first instead of lengthening the pile, which is
     *                            what you want when the second one is a newer
     *                            count of the same unread channel.
     * @param  bool  $renotify  Whether replacing under that tag should buzz again.
     *                          Off by default: a quietly updated bubble is the
     *                          point of a tag.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $icon = null,
        public readonly ?string $badge = null,
        public readonly ?string $url = null,
        public readonly ?string $tag = null,
        public readonly bool $renotify = false,
    ) {}
}
