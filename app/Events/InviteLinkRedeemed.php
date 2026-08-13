<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody joined a workspace through an invitation link.
 *
 * Only on the way that actually worked: a link that turned out to be withdrawn,
 * used up or past its date changes nothing and announces nothing — see
 * RedeemInviteLink, which answers false for all three.
 *
 * Worth its own event beside ChannelMemberJoined, which fires afterwards for
 * every channel the link put them in. That one is about a room; this is about
 * the front door, and it carries which link opened it — so a workspace can tell
 * the people who came in through the customer link from the people who came in
 * through the one on the careers page.
 */
class InviteLinkRedeemed
{
    use Dispatchable;

    public function __construct(
        public readonly int $inviteLinkId,
        public readonly int $userId,
    ) {}
}
