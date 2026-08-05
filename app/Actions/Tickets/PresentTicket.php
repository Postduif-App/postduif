<?php

namespace App\Actions\Tickets;

use App\Actions\Chat\CensorBlockedWords;
use App\Enums\SystemRole;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentAttachment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PresentTicket
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * Blocklists already fetched, keyed by workspace. A board draws thirty
     * tickets from one workspace, and without this each of them asks the
     * database for the same list.
     *
     * @var array<int, array<int, string>>
     */
    private array $blockedWords = [];

    /**
     * Which members of a workspace are guests, keyed by workspace. A set rather
     * than a list, so the lookup stays a hash hit.
     *
     * @var array<int, array<int, true>>
     */
    private array $guestIds = [];

    /**
     * A ticket as the board lists it.
     *
     * Deliberately without the timeline: a board of thirty rows would otherwise
     * drag every comment ever written into the payload for something it never
     * draws. What stays shared with handle() is the censoring and the author
     * rules — those must never differ between one view of a ticket and another.
     *
     * @return array<string, mixed>
     */
    public function summary(Ticket $ticket): array
    {
        $ticket->loadMissing(['opener', 'assignee']);

        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'channelId' => $ticket->channel_id,
            'title' => $this->censor($ticket->workspace_id, $ticket->title),
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'opener' => $this->person($ticket->workspace_id, $ticket->opener),
            'assignee' => $this->person($ticket->workspace_id, $ticket->assignee),
            'commentCount' => $ticket->comments_count ?? $ticket->comments()->count(),
            'createdAt' => $ticket->created_at?->toIso8601String(),
            'dueAt' => $ticket->due_at?->toIso8601String(),
            'closedAt' => $ticket->closed_at?->toIso8601String(),
            // What "stil blijven liggen" is measured against on the board, so it
            // has to mean the last thing that happened to the ticket rather than
            // the last time a column was touched.
            'lastActivityAt' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A ticket with everything its own page shows.
     *
     * @return array<string, mixed>
     */
    public function handle(Ticket $ticket): array
    {
        $ticket->loadMissing(['opener', 'assignee', 'allComments.author', 'events.actor', 'sourceMessage']);

        return [
            ...$this->summary($ticket),
            'body' => $this->censor($ticket->workspace_id, $ticket->body),
            'source' => $this->source($ticket),
            'timeline' => $this->timeline($ticket),
        ];
    }

    /**
     * The files on one comment, with the only URL that leads to them.
     *
     * Built here rather than on the model: the address needs the workspace and
     * the channel the ticket hangs in, and a model that had to know its own
     * route would have to know those too.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Ticket $ticket, TicketComment $comment): array
    {
        $channel = $ticket->channel;

        // The workspace itself, not its id: it is bound by slug, so an id here
        // would build a URL that resolves to nothing.
        $workspace = $channel?->workspace;

        if ($channel === null || $workspace === null) {
            return [];
        }

        return $comment->attachments
            ->map(fn (TicketCommentAttachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mimeType' => $attachment->mime_type,
                'size' => $attachment->size,
                'isImage' => $attachment->isImage(),
                /*
                 * No smaller copy is made for these. A ticket shows a handful
                 * of files rather than the hundreds a channel scrolls past, so
                 * the original is what gets drawn — but the field is sent all
                 * the same, because the renderer is shared and a shape that is
                 * "the same except one key" is the one that trips it up.
                 */
                'previewUrl' => null,
                'url' => route('chat.tickets.comments.attachments.show', [
                    $workspace,
                    $channel,
                    $ticket,
                    $comment,
                    $attachment,
                ]),
            ])
            ->all();
    }

    /**
     * Comments and events in one chronological list.
     *
     * Merged here rather than in the browser: they are two tables with two
     * shapes, and letting the page interleave them would put the ordering rule
     * in a place where nothing tests it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(Ticket $ticket): array
    {
        $ticket->loadMissing(['allComments.author', 'allComments.attachments', 'events.actor', 'channel.workspace']);

        $comments = $ticket->allComments->map(fn (TicketComment $comment): array => [
            'kind' => 'comment',
            'id' => "comment-{$comment->id}",
            'author' => $this->person($ticket->workspace_id, $comment->author),
            // A withdrawn comment leaves its place but not its words, exactly
            // like a deleted message in the channel.
            'body' => $comment->isDeleted() ? '' : $this->censor($ticket->workspace_id, $comment->body),
            'deleted' => $comment->isDeleted(),
            'editedAt' => $comment->edited_at?->toIso8601String(),
            'createdAt' => $comment->created_at?->toIso8601String(),
            // A withdrawn comment keeps its place but hands out nothing: taking
            // the words back and leaving the screenshot would be half a
            // withdrawal.
            'attachments' => $comment->isDeleted()
                ? []
                : $this->attachments($ticket, $comment),
        ]);

        $events = $ticket->events->map(fn (TicketEvent $event): array => [
            'kind' => 'event',
            'id' => "event-{$event->id}",
            'author' => $this->person($ticket->workspace_id, $event->actor),
            'type' => $event->type->value,
            'payload' => $event->payload,
            'createdAt' => $event->created_at?->toIso8601String(),
        ]);

        return $comments->concat($events)
            ->sortBy('createdAt')
            ->values()
            ->all();
    }

    /**
     * The message this ticket was promoted out of, trimmed to what a back link
     * shows.
     *
     * A deleted original keeps its place, for the same reason a quote does: the
     * ticket has to be able to say it came from somewhere, and the text itself
     * stays behind.
     *
     * @return array{id: string, channelId: int, author: string, snippet: string, deleted: bool}|null
     */
    private function source(Ticket $ticket): ?array
    {
        $message = $ticket->sourceMessage;

        if ($message === null) {
            return null;
        }

        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'channelId' => $message->channel_id,
            'author' => $message->isFromBot()
                ? (string) $message->bot_name
                : $message->author->name,
            'snippet' => $deleted ? '' : Str::limit($this->censor($ticket->workspace_id, $message->body), 160),
            'deleted' => $deleted,
        ];
    }

    /**
     * Somebody on a ticket, marked as a guest or not.
     *
     * Null stays null: an event with nobody behind it is the scheduled reminder
     * or a webhook, and the page draws that as "systeem" rather than inventing a
     * person for it.
     *
     * @return array{id: int, name: string, isGuest: bool}|null
     */
    private function person(int $workspaceId, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'isGuest' => isset($this->guestsIn($workspaceId)[$user->id]),
        ];
    }

    /**
     * @return array<int, true>
     */
    private function guestsIn(int $workspaceId): array
    {
        if (isset($this->guestIds[$workspaceId])) {
            return $this->guestIds[$workspaceId];
        }

        $ids = Workspace::query()
            ->whereKey($workspaceId)
            ->firstOrFail()
            ->members()
            ->wherePivot('role', SystemRole::Guest->value)
            ->pluck('users.id');

        // Built by hand rather than through flip()->map(): a set of ids is what
        // the callers look things up in, and spelling it out is the only way to
        // say "the value is always true" in a type.
        $guests = [];

        foreach ($ids as $id) {
            $guests[(int) $id] = true;
        }

        return $this->guestIds[$workspaceId] = $guests;
    }

    /**
     * Text with the workspace's blocked words masked.
     *
     * On the way out rather than on the way in, the same as messages: a word
     * added to the list today also disappears from tickets raised last month.
     */
    private function censor(int $workspaceId, string $text): string
    {
        /*
         * firstOrFail rather than a null-safe lookup: a ticket cannot outlive
         * its workspace — the foreign key cascades — so "no workspace" is a
         * broken database rather than a case to render around. Still only one
         * query per workspace, thanks to the cache.
         */
        $words = $this->blockedWords[$workspaceId] ??= Workspace::query()
            ->whereKey($workspaceId)
            ->firstOrFail()
            ->blocked_words;

        return $this->censorBlockedWords->handle($text, $words);
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $tickets): array
    {
        return $tickets->map(fn (Ticket $ticket) => $this->summary($ticket))->all();
    }
}
