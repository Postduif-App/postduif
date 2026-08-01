<?php

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
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

/**
 * Presence channel for a whole workspace: who is in the application right now,
 * regardless of which conversation they happen to have open.
 *
 * A second channel rather than something read off the per-channel rosters,
 * because those only ever know about people who opened the same channel you
 * did. Somebody working in another channel is online, and a list that called
 * them away would be wrong in the one thing it exists to say.
 *
 * Membership is the whole test — the panel is a list of members, and who may
 * see it was already decided when the page was built.
 *
 * @return array{id: int}|null
 */
Broadcast::channel('chat.workspace.{workspace}', function (User $user, Workspace $workspace) {
    if (! $workspace->hasMember($user)) {
        return null;
    }

    /*
     * Only the id. The panel already has every name, face and status from the
     * page — this channel answers one question, and publishing a second copy of
     * somebody's details here is a second thing that can go stale.
     */
    return ['id' => $user->id];
});
