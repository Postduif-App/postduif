<?php

namespace App\Actions\UploadLinks;

use App\Actions\Chat\AnnounceInbox;
use App\Actions\Chat\SendMessage;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Models\User;
use App\Notifications\FilesReceived;

/**
 * Tell whoever put the link out that something came in.
 *
 * The same three ways NotifyContractAuthor uses, for the same three moments: a
 * line in the channel they named, so it is readable in passing; an inbox row,
 * so the badge and the sound go off for somebody who is in the app; and a mail
 * or push for somebody who is not.
 */
class NotifyUploadLinkOwner
{
    /** How the application signs the line it writes in a channel. */
    public const BOT_NAME = 'Bestanden';

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly AnnounceInbox $announceInbox,
    ) {}

    public function handle(UploadLinkSubmission $submission): void
    {
        $submission->loadMissing(['uploadLink.owner', 'uploadLink.workspace', 'uploadLink.notifyChannel', 'media']);

        $link = $submission->uploadLink;

        $message = $this->postToChat($link, $submission);

        $owner = $link->owner;

        // The member who put the link out has left. The line in the channel is
        // still worth writing — somebody there may pick it up — but there is
        // nobody left to mail.
        if ($owner === null) {
            return;
        }

        if ($message !== null) {
            $this->recordInInbox($link, $owner, $message);
        }

        $owner->notify(new FilesReceived($submission));
    }

    private function postToChat(UploadLink $link, UploadLinkSubmission $submission): ?Message
    {
        $channel = $this->destination($link);

        if ($channel === null) {
            return null;
        }

        $body = trans_choice('chat.upload_link.received', $submission->files()->count(), [
            'name' => $submission->name,
            'title' => $link->title,
        ])."\n".route('chat.transfers.index', $link->workspace);

        return $this->sendMessage->fromSystem($channel, $body, self::BOT_NAME);
    }

    /**
     * The channel named when the link was made, if it still belongs here.
     *
     * The same two refusals NotifyContractAuthor makes: a channel that is no
     * longer in this workspace would be a leak, and an archived one is a line
     * nobody reads.
     */
    private function destination(UploadLink $link): ?Channel
    {
        $channel = $link->notifyChannel;

        if ($channel === null) {
            return null;
        }

        if ($channel->workspace_id !== $link->workspace_id || $channel->archived_at !== null) {
            return null;
        }

        return $channel;
    }

    /**
     * One row per channel, bumped on every submission and marked unread again,
     * the way a contract's row is: a link three people use in an afternoon is
     * one thing to look at, not three.
     */
    private function recordInInbox(UploadLink $link, User $owner, Message $message): void
    {
        if (! $message->channel->members()->whereKey($owner->id)->exists()) {
            return;
        }

        InboxItem::updateOrCreate([
            'type' => InboxItemType::UploadReceived,
            'user_id' => $owner->id,
            'channel_id' => $message->channel_id,
        ], [
            'message_id' => $message->id,
            // Nobody with an account did this; the sender's name is in the
            // message the row points at.
            'actor_id' => null,
            'read_at' => null,
        ]);

        $this->announceInbox->handle($link->workspace_id, [$owner->id]);
    }
}
