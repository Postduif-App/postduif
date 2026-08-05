<?php

namespace App\Actions\Chat;

use App\Events\LinkPreviewAttached;
use App\Models\LinkPreview;
use App\Models\Message;

/**
 * Tell the conversations that were waiting that the card can be drawn.
 *
 * The link was looked up on a queue, a second or two after the message landed
 * on everybody's screen. Somebody has to go back and say so, and that is a
 * search rather than a lookup: a preview belongs to a URL, and the messages
 * carrying that URL are not known to it.
 *
 * Bounded on both sides, because "every message that ever mentioned this link"
 * is not a set anybody wants to broadcast to. Only messages young enough to
 * still be on a screen, and only a handful of them.
 */
class AnnounceLinkPreview
{
    /**
     * How far back to look.
     *
     * A look-up takes seconds; this allows for a queue that was asleep, a
     * worker that was restarting, or a page that took its time answering.
     * Beyond that the reader has moved on and would get a message jumping under
     * their eyes for no reason they can see.
     */
    private const MINUTES = 15;

    /**
     * How many at once. One link goes into a handful of channels in the moment
     * it is shared; a number far above that is somebody pasting the same URL
     * into fifty places, and fifty broadcasts is not the answer to that.
     */
    private const MAX_MESSAGES = 25;

    public function handle(LinkPreview $preview): void
    {
        if (! $preview->isUsable()) {
            return;
        }

        $messages = Message::query()
            ->with('workspace')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMinutes(self::MINUTES))
            /*
             * The escape is not decoration: a URL may hold a % or an _, both of
             * which are wildcards to LIKE. Without it a link with a percent in
             * it would match messages that merely rhyme with it.
             */
            ->where('body', 'like', '%'.addcslashes($preview->url, '%_\\').'%')
            ->latest('id')
            ->limit(self::MAX_MESSAGES)
            ->get();

        foreach ($messages as $message) {
            if (! $message->workspace->link_previews_enabled) {
                continue;
            }

            /*
             * Only where this is the link the card would be for. A message can
             * carry several, and the card is drawn for the first one — telling
             * a conversation about the third would be a broadcast that changes
             * nothing on screen.
             */
            if (LinkPreview::firstUrlIn($message->body) !== $preview->url) {
                continue;
            }

            LinkPreviewAttached::dispatch($message);
        }
    }
}
