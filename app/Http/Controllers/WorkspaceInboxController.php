<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Chat\PresentMessage;
use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
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
 * same live connection. Nothing is marked read here — that happens by opening
 * the channel, which is where the answer gets written.
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
        ]);
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
