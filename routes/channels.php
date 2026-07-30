<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Presence channel for a chat channel: it carries new messages, tells everyone
 * who is currently looking at the conversation, and relays typing whispers.
 *
 * The same ChannelPolicy that guards the HTTP route guards the socket, so a
 * member cannot subscribe their way into a private channel they may not open.
 * Returning an array admits the user and publishes that data to the other
 * members; returning null refuses the subscription.
 *
 * @return array{id: int, name: string}|null
 */
Broadcast::channel('chat.channel.{channel}', function (User $user, Channel $channel) {
    if ($user->cannot('view', $channel)) {
        return null;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
