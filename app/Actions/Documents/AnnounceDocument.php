<?php

namespace App\Actions\Documents;

use App\Actions\Chat\SendMessage;
use App\Models\Document;
use App\Models\User;

/**
 * Says in the conversation that a document was started, or that it now goes by a
 * different name.
 *
 * A document lives behind a tab, and a tab is a thing people stop clicking. The
 * document that nobody was told about is a document of one — which is the exact
 * failure a shared document exists to prevent, so the channel gets told.
 *
 * Only those two moments. Saving happens by itself every few seconds of quiet
 * while somebody writes, and a channel that reported each of those would be
 * muted by the afternoon — after which the one message that mattered is gone
 * too. What changed inside the document is the document's business; that it
 * exists, and what it is called, is the channel's.
 */
class AnnounceDocument
{
    /**
     * The name the announcement posts under. A constant rather than the
     * workspace's name, the same choice AnnounceTicket makes: it is the same
     * voice in every channel, and a reader recognises it faster than a name
     * that shifts.
     */
    private const BOT_NAME = 'Document';

    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function created(Document $document, User $author): void
    {
        $this->announce($document, sprintf(
            '%s begon document #%d — %s',
            $author->name,
            $document->number,
            $document->title,
        ));
    }

    /**
     * A rename is announced because the old name is what people wrote down
     * elsewhere. Without this, a link somebody pasted last month points at
     * something that appears to have vanished.
     */
    public function renamed(Document $document, User $editor): void
    {
        $this->announce($document, sprintf(
            '%s hernoemde document #%d naar %s',
            $editor->name,
            $document->number,
            $document->title,
        ));
    }

    /**
     * Nothing is said in a channel that switched announcements off, and nothing
     * in one that no longer keeps documents at all — the second is what stops an
     * old document being renamed from putting a bot message in a channel that
     * has long since moved on.
     */
    private function announce(Document $document, string $body): void
    {
        $channel = $document->channel;

        if (! $channel->hasDocuments() || ! $channel->document_announcements) {
            return;
        }

        $this->sendMessage->fromSystem($channel, $body, self::BOT_NAME);
    }
}
