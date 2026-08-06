<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Tickets\PresentTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every ticket in the workspace, across the channels this member can see.
 *
 * Inside the chat shell rather than on a page of its own, the same choice the
 * per-channel board makes: it needs the same sidebar, the same unread counts
 * and the same live connection, and a second shell is how the two would drift.
 *
 * Visibility is not decided here. The list is scoped to the channels the
 * sidebar already shows, which is TicketPolicy::viewBoard's answer expressed as
 * a query — a second rule stated in its own words is a second rule to get
 * wrong.
 */
class WorkspaceTicketController extends Controller
{
    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentTicket $presentTicket,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));

        $channels = $this->buildChatShell->visibleChannels($workspace, $user)
            ->filter(fn (Channel $channel): bool => $channel->hasTickets());

        $filters = $this->filters($request);

        return Inertia::render('chat/tickets', [
            ...$this->buildChatShell->handle($workspace, $user),
            'rows' => $this->rows($channels, $filters, $user),
            /*
             * Counted over everything visible rather than over the filtered
             * list: these numbers are the filter buttons, and a count that
             * already had the filter applied would read zero for every status
             * except the one being looked at.
             */
            'counts' => $this->counts($channels),
            /*
             * The channels that keep tickets at all, for the channel filter and
             * so the page can say why it is empty.
             *
             * canCreate is a narrower question than being in this list: you can
             * filter on a channel you only read along in, and opening a ticket
             * there means having joined it and passing its ticket policy. The
             * new-ticket dialog picks from the ones that say true.
             */
            'ticketChannels' => $channels
                ->map(fn (Channel $channel): array => [
                    'id' => $channel->id,
                    'label' => $channel->displayNameFor($user),
                    'canCreate' => $user->can('create', [Ticket::class, $channel]),
                ])->values()->all(),
            'filters' => $filters,
            'ticket' => $this->openTicket($channels, $request->query('ticket'), $user),
        ]);
    }

    /**
     * The ticket named by ?ticket= in the URL, or null.
     *
     * Addressed by its number, which is claimed per workspace rather than per
     * channel — so one number is enough here, even though the rows come from
     * several channels at once.
     *
     * The channel's members travel along because handing a ticket to somebody
     * means picking from the channel it lives in, and this page has no channel
     * on screen to take that list from.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<string, mixed>|null
     */
    private function openTicket($channels, ?string $number, User $user): ?array
    {
        if ($number === null) {
            return null;
        }

        $ticket = Ticket::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->where('number', $number)
            ->first();

        if ($ticket === null) {
            return null;
        }

        $channel = $channels->firstWhere('id', $ticket->channel_id);

        return [
            ...$this->presentTicket->handle($ticket),
            'channelId' => $ticket->channel_id,
            'channelLabel' => $channel?->displayNameFor($user),
            'channelMembers' => $channel === null ? [] : $channel->members
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'username' => $member->username,
                    'isGuest' => false,
                    'statusEmoji' => $member->status_emoji,
                    'statusText' => $member->status_text,
                    'availability' => $member->availability->value,
                ])->values()->all(),
            // Asked per ticket, not per page: a customer may confirm their own
            // ticket is done and may not decide it is urgent, and this list can
            // hold both kinds at once.
            'canManage' => $user->can('manage', $ticket),
            'canConfirm' => $user->can('confirm', $ticket),
            'canEdit' => $user->can('update', $ticket),
            'canDelete' => $user->can('delete', $ticket),
        ];
    }

    /**
     * The filters as the page will echo them back.
     *
     * Unknown values are dropped rather than refused: a filter can only come
     * from a link somebody kept, and the answer to a status that no longer
     * exists is the unfiltered list, not an error page.
     *
     * @return array{status: string|null, priority: string|null, assignee: int|null, channel: int|null, open: bool}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => TicketStatus::tryFrom((string) $request->query('status'))?->value,
            'priority' => TicketPriority::tryFrom((string) $request->query('priority'))?->value,
            'assignee' => $request->integer('assignee') ?: null,
            'channel' => $request->integer('channel') ?: null,
            // The default view, and the one people come here for: what is still
            // outstanding. Explicit rather than implied by "no status filter",
            // so the page can offer a way out of it.
            'open' => $request->query('open', '1') === '1',
        ];
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function rows($channels, array $filters, User $user): array
    {
        $tickets = Ticket::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->with(['opener', 'assignee'])
            ->withCount('comments')
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            // Only when no single status is asked for: "open" and "wacht op
            // klant" together would otherwise be an empty list by definition.
            ->when($filters['status'] === null && $filters['open'], fn (Builder $query) => $query->open())
            ->when($filters['priority'], fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['assignee'], fn (Builder $query, int $assignee) => $query->where('assigned_to', $assignee))
            ->when($filters['channel'], fn (Builder $query, int $channel) => $query->where('channel_id', $channel))
            ->inBoardOrder()
            ->limit(200)
            ->get();

        $labels = $channels->mapWithKeys(
            fn (Channel $channel): array => [$channel->id => $channel->displayNameFor($user)]
        );

        return $tickets->map(fn (Ticket $ticket): array => [
            ...$this->presentTicket->summary($ticket),
            // The channel by name, because that is the column this view adds
            // over the per-channel board: without it a ticket is a title with
            // no idea where it came from.
            'channelLabel' => $labels[$ticket->channel_id] ?? null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @return array<string, int>
     */
    private function counts($channels): array
    {
        return Ticket::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
