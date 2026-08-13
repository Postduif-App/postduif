<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Chat\PresentMessage;
use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\PollOption;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything that asks something of this member, in one list.
 *
 * The rows already fed the badges in the sidebar; what they never had was a
 * place to be read. Four badges on a Monday morning means four channels to walk
 * through, and the one thing somebody wants to know — what is being asked of me
 * — is exactly what a badge cannot say.
 *
 * Inside the chat shell, like the ticket list: same sidebar, same unread counts,
 * same live connection. A row is marked off by being opened — see open() — and
 * a mention additionally by reading past it in its channel, which is the same
 * event arrived at from the other side.
 */
class WorkspaceInboxController extends Controller
{
    /** Enough to catch up on; older than this and the channel is the place to look. */
    private const LIMIT = 50;

    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentMessage $presentMessage,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        return $this->render($request, $workspace, $this->requestedType($request));
    }

    /**
     * The inbox narrowed to mentions.
     *
     * Its own route rather than a query string, because it is where the sidebar
     * badge has always pointed and where somebody's bookmarks still go. Same
     * page, one tab preselected.
     */
    public function mentions(Request $request, Workspace $workspace): Response
    {
        return $this->render($request, $workspace, InboxItemType::Mention);
    }

    /**
     * Open a row: mark it off, then go where it points.
     *
     * The destination is worked out here rather than in the browser because
     * this is already the place that knows what a row hangs off — the same
     * knowledge survives() uses to decide a row is still worth showing. It
     * also means the mark and the jump are one request, so a row cannot end
     * up read at a page the member never reached, or reached and left unread.
     *
     * Marked read even when it already was: an inbox row is opened far more
     * often than it is first opened, and a branch here would only buy a
     * write.
     */
    public function open(Request $request, Workspace $workspace, InboxItem $item): RedirectResponse
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));

        /*
         * Somebody else's row is a 404 rather than a 403: that a row exists at
         * all is already something about who was named where, and an inbox id
         * is a small enough number to walk.
         */
        abort_unless($item->user_id === $user->id, 404);

        // The same fence the list stands behind. Being named in a channel you
        // were since removed from leaves the row, and this is the door it
        // would otherwise open.
        abort_unless(
            $this->buildChatShell->visibleChannels($workspace, $user)
                ->pluck('id')
                ->contains($item->channel_id),
            404,
        );

        $item->update(['read_at' => now()]);

        return redirect()->to($this->destination($workspace, $item));
    }

    /**
     * Where a row points.
     *
     * Straight to the message rather than to the channel: the point of the
     * list is to answer somebody, and landing at the bottom of a busy channel
     * means finding the line again yourself. A poll is the odd one out — it is
     * reached through a link in a message body rather than through a column,
     * so the poll itself is its own destination.
     *
     * Whatever it pointed at may be gone by now, in which case the honest
     * answer is the list it came from.
     */
    private function destination(Workspace $workspace, InboxItem $item): string
    {
        if ($item->type === InboxItemType::PollVote) {
            return $item->poll === null
                ? route('chat.inbox.index', $workspace)
                : route('chat.polls.show', [$workspace, $item->poll]);
        }

        if ($item->message === null || $item->message->isDeleted()) {
            return route('chat.inbox.index', $workspace);
        }

        return route('chat.show', [$workspace, $item->message->channel_id])
            .'#message-'.$item->message_id;
    }

    private function requestedType(Request $request): ?InboxItemType
    {
        return InboxItemType::tryFrom((string) $request->query('type'));
    }

    private function render(Request $request, Workspace $workspace, ?InboxItemType $type): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));

        /*
         * Scoped to the channels the sidebar shows rather than to every row a
         * member has. Somebody who was named in a channel and then removed from
         * it still has the row; showing it here would hand them a line out of a
         * conversation they can no longer open.
         */
        $channels = $this->buildChatShell->visibleChannels($workspace, $user)
            ->pluck('id');

        $items = InboxItem::query()
            ->where('user_id', $user->id)
            ->whereIn('channel_id', $channels)
            ->when($type, fn ($query) => $query->ofType($type))
            ->with([
                'actor:id,name',
                'message.author',
                'message.channel',
                'message.workspace',
                'poll.channel',
                'poll.options.votes:id,poll_option_id,user_id',
            ])
            // Unread first, and within each half the most recent: what still
            // wants an answer belongs above what has already had one.
            ->orderByRaw('read_at is not null')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            // Whatever the row pointed at is gone — a deleted message, a poll
            // that went with its channel — so there is nothing left to show.
            ->filter(fn (InboxItem $item): bool => $this->survives($item));

        return Inertia::render('chat/inbox', [
            ...$this->buildChatShell->handle($workspace, $user),
            'filter' => $type?->value,
            'items' => $items
                ->map(fn (InboxItem $item): array => $this->present($item, $user))
                ->values()
                ->all(),
            /*
             * What this member has asked to be reminded of and has not been
             * reminded of yet.
             *
             * Sent with every filter rather than only with the reminder one, so
             * that the tab can decide where to draw it without a second
             * request. It is a short list by nature — a reminder leaves it the
             * moment it goes off — and for most people it is empty.
             */
            'pendingReminders' => $this->pendingReminders($user, $channels),
        ]);
    }

    /**
     * Reminders still to come, soonest first.
     *
     * Scoped to the same channels the list above is, and for the same reason: a
     * reminder set in a channel somebody has since been removed from would
     * otherwise show them a line out of a conversation they can no longer open.
     * The reminder itself is dropped when it goes off — see
     * DeliverDueReminders, which asks the same question at delivery — so this
     * is the screen agreeing with the sweep rather than a second rule.
     *
     * @param  Collection<int, int>  $channels
     * @return array<int, array<string, mixed>>
     */
    private function pendingReminders(User $user, Collection $channels): array
    {
        return Reminder::query()
            ->pendingFor($user)
            ->whereIn('channel_id', $channels)
            ->with(['message.author', 'channel'])
            ->limit(self::LIMIT)
            ->get()
            // The message was withdrawn between setting the reminder and now.
            // Nothing to point at, so nothing to draw.
            ->filter(fn (Reminder $reminder): bool => $reminder->message !== null
                && ! $reminder->message->isDeleted())
            ->map(fn (Reminder $reminder): array => [
                'remindAt' => $reminder->remind_at->toIso8601String(),
                'note' => $reminder->note,
                'channel' => [
                    'id' => $reminder->channel_id,
                    'label' => $reminder->channel->displayNameFor($user),
                    'type' => $reminder->channel->type->value,
                ],
                /*
                 * Through the same summary the rows above use, so a word
                 * masked in the channel stays masked here and a bot line names
                 * the bot. Spread first and then named over: its 'id' is the
                 * message's, and this row is addressed by the reminder.
                 */
                ...$this->presentMessage->threadSummary($reminder->message),
                'id' => $reminder->id,
                'messageId' => $reminder->message_id,
            ])
            ->values()
            ->all();
    }

    private function survives(InboxItem $item): bool
    {
        if ($item->type === InboxItemType::PollVote) {
            return $item->poll !== null;
        }

        return $item->message !== null && ! $item->message->isDeleted();
    }

    /** @return array<string, mixed> */
    private function present(InboxItem $item, User $user): array
    {
        $row = [
            'id' => $item->id,
            'type' => $item->type->value,
            'label' => $item->type->label(),
            'readAt' => $item->read_at?->toIso8601String(),
            // Empty on a poll row, which stands for every vote at once rather
            // than for the last person to cast one.
            'actor' => $item->actor?->name,
        ];

        if ($item->type === InboxItemType::PollVote) {
            $channel = $item->poll->channel;

            return [
                ...$row,
                'poll' => [
                    'id' => $item->poll->id,
                    'question' => $item->poll->question,
                    // Voters, not votes: on a poll that takes more than one
                    // answer the two are different numbers, and the asker is
                    // waiting on people rather than on ticks.
                    'voterCount' => $item->poll->options
                        ->flatMap(fn (PollOption $option) => $option->votes->pluck('user_id'))
                        ->unique()
                        ->count(),
                ],
                'channel' => [
                    'id' => $channel->id,
                    'label' => $channel->displayNameFor($user),
                    'type' => $channel->type->value,
                ],
            ];
        }

        /*
         * The thread shape carries the censoring and the author rules, so a
         * word masked in the channel stays masked here. Spread first and then
         * named over: its 'id' is the message's, and this row is addressed by
         * the inbox item.
         */
        $summary = $this->presentMessage->threadSummary($item->message);

        return [
            ...$row,
            ...$summary,
            'id' => $item->id,
            'messageId' => $summary['id'],
            'channel' => [
                'id' => $item->message->channel_id,
                'label' => $item->message->channel->displayNameFor($user),
                'type' => $item->message->channel->type->value,
            ],
        ];
    }
}
