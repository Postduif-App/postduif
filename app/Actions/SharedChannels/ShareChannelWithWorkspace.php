<?php

namespace App\Actions\SharedChannels;

use App\Events\ChannelShareOffered;
use App\Features\SharedChannels;
use App\Models\Channel;
use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

class ShareChannelWithWorkspace
{
    /**
     * Offer a channel to another workspace.
     *
     * Nothing is granted here. The row this writes is an invitation, and it
     * stays inert until somebody on the other side accepts it — which is the
     * whole difference between this feature and simply adding an outsider to a
     * channel. One workspace deciding that another one is now reading along
     * would be a decision made about people who never agreed to it.
     *
     * Written with updateOrCreate rather than insert, because the table keeps
     * one standing arrangement per pair: offering again after a refusal or a
     * withdrawal is the same arrangement being re-opened, and the three
     * timestamps are cleared so the row reads as the fresh offer it is.
     *
     * @throws RuntimeException when either workspace has the feature off, or
     *                          when the channel is not one that can be shared
     */
    public function handle(Channel $channel, Workspace $guest, User $inviter, bool $canPost = true): ChannelShare
    {
        $this->guard($channel, $guest);

        $share = ChannelShare::query()->updateOrCreate(
            ['channel_id' => $channel->id, 'workspace_id' => $guest->id],
            [
                'invited_by' => $inviter->id,
                'can_post' => $canPost,
                /*
                 * Every state cleared, including accepted_at. Re-offering a
                 * live share is how the host changes can_post, and leaving the
                 * acceptance in place would be the neater-looking choice — but
                 * it would also let a host widen "may read" into "may post"
                 * without the other side ever being asked again. The narrower
                 * reading is the safe one: any change to the terms is a new
                 * offer.
                 */
                'accepted_by' => null,
                'accepted_at' => null,
                'declined_at' => null,
                'revoked_at' => null,
            ]
        );

        /*
         * Announced on every offer, including a re-offer of a live share —
         * which is a new offer, for the reason set out above: the terms
         * changed and the other side has to be asked again.
         */
        ChannelShareOffered::dispatch($share->id);

        return $share;
    }

    /**
     * @throws RuntimeException
     */
    private function guard(Channel $channel, Workspace $guest): void
    {
        if ($channel->workspace_id === $guest->id) {
            throw new RuntimeException('A channel cannot be shared with the workspace that owns it.');
        }

        /*
         * A conversation between two named people is not a room, and there is
         * nobody who could answer for opening it: both wrote it, and neither
         * agreed to a third party reading along.
         */
        if ($channel->isDirect()) {
            throw new RuntimeException('A direct conversation cannot be shared.');
        }

        if ($channel->archived_at !== null) {
            throw new RuntimeException('An archived channel cannot be shared.');
        }

        /*
         * Both sides, never one. The host has to offer the feature, and the
         * guest has to be a workspace that accepts this kind of arrangement at
         * all — a beheerder who switched shared channels off should not find
         * their people in somebody else's channel because the invitation came
         * from outside.
         */
        if (! $channel->workspace->hasFeature(SharedChannels::class)) {
            throw new RuntimeException('This workspace does not offer shared channels.');
        }

        if (! $guest->hasFeature(SharedChannels::class)) {
            throw new RuntimeException('That workspace does not accept shared channels.');
        }
    }
}
