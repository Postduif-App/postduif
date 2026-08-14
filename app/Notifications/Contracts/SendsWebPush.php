<?php

namespace App\Notifications\Contracts;

use App\Models\User;
use App\Notifications\Messages\WebPushMessage;

/**
 * A notification that knows how to become a bubble in a browser's tray.
 *
 * Written down as a contract rather than left as a convention, because
 * WebPushChannel is handed the framework's base Notification and has no other
 * way to know the method is there. Without it the channel is one typo in a
 * via() away from a fatal error at delivery time — in a queue worker, on
 * somebody else's evening.
 */
interface SendsWebPush
{
    public function toWebPush(User $notifiable): WebPushMessage;
}
