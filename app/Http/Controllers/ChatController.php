<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Chat\HideDirectMessage;
use App\Actions\Chat\MarkChannelRead;
use App\Actions\Chat\PresentMessage;
use App\Actions\Documents\PresentDocument;
use App\Actions\Huddles\IceServers;
use App\Actions\Tickets\PresentTicket;
use App\Actions\Workspace\CreateHomeChannel;
use App\Enums\WorkspaceAbility;
use App\Features\Documents as DocumentsFeature;
use App\Features\Huddles as HuddlesFeature;
use App\Features\Tickets;
use App\Models\Bookmark;
use App\Models\Channel;
use App\Models\ChannelLink;
use App\Models\Document;
use App\Models\EphemeralNotice;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\Message;
use App\Models\ScheduledHuddle;
use App\Models\ScheduledMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\ButtonTrigger;
use App\Workflows\Triggers\SlashCommandTrigger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly PresentMessage $presentMessage,
        private readonly BuildChatShell $buildChatShell,
        private readonly MarkChannelRead $markChannelRead,
        private readonly PresentTicket $presentTicket,
        private readonly PresentDocument $presentDocument,
        private readonly HideDirectMessage $hideDirectMessage,
        private readonly CreateHomeChannel $createHomeChannel,
        private readonly IceServers $iceServers,
    ) {}

    /**
     * The landing page after signing in. Sends the member to their workspace;
     * once a member can belong to several, this is where the picker goes.
     */
    public function home(Request $request): RedirectResponse|Response
    {
        // Same tiebreak as the switcher in BuildChatShell, so "the first one"
        // means the same thing in both places.
        $workspace = $request->user()->workspaces()
            ->oldest('workspace_user.joined_at')
            ->orderBy('workspaces.id')
            ->first();

        /*
         * Somebody who belongs nowhere gets told so rather than shown a 404.
         *
         * They used to be sent to a form to make a workspace of their own.
         * Workspaces are handed out from the admin panel now, so the only
         * honest thing this page can do is say what is missing and who fixes
         * it — an account with nothing behind it is otherwise indistinguishable
         * from a broken one.
         */
        if ($workspace === null) {
            return Inertia::render('workspaces/none');
        }

        return redirect()->route('chat.index', $workspace);
    }

    /**
     * Drop the member into the most recently active channel they can see.
     */
    public function index(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeMembership($request->user(), $workspace);

        $channel = $this->firstVisibleChannel($request->user(), $workspace);

        /*
         * A workspace with nothing in it gets its first channel here rather
         * than answering 404 at the door.
         *
         * Every workspace made from now on starts with one — see
         * CreateHomeChannel — but the ones that already exist do not, and the
         * only way out of an empty workspace was a create-channel dialog that
         * a member without the right cannot open. That is a locked room with
         * the key on the inside.
         *
         * Asked of the workspace rather than of what this member can see. A
         * guest is shown only the channels they were invited to, so a guest
         * standing in a busy workspace also sees none — and building them a
         * channel because of it would be reading "I cannot see anything" as
         * "there is nothing here".
         */
        if ($channel === null && $workspace->channels()->doesntExist()) {
            $this->createHomeChannel->handle($workspace);

            $channel = $this->firstVisibleChannel($request->user(), $workspace);
        }

        abort_if($channel === null, 404);

        return redirect()->route('chat.show', [$workspace, $channel]);
    }

    /**
     * The channel to land in: the one spoken in most recently, of those this
     * member is allowed to see.
     */
    private function firstVisibleChannel(User $user, Workspace $workspace): ?Channel
    {
        return Channel::query()
            ->reachableFrom($workspace)
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->orderByRaw('last_message_at desc nulls last')
            ->first();
    }

    public function show(Request $request, Workspace $workspace, Channel $channel): Response
    {
        $user = $request->user();

        $this->authorizeMembership($user, $workspace);

        /*
         * The channel has to belong on this workspace's screen. That used to
         * mean "it is one of theirs" and now also covers a channel another
         * workspace has opened to this one — which is why the check moved into
         * a scope: the sidebar and this line have to agree about which rooms
         * exist here, or a member would be looking at a channel they cannot
         * open, or opening one that is not in their list.
         *
         * Still a 404 and not a 403. Whether a channel exists in a workspace
         * you are standing in is not a question worth answering to somebody who
         * guessed an id.
         */
        abort_unless(
            Channel::query()->whereKey($channel->id)->reachableFrom($workspace)->exists(),
            404,
        );

        $this->authorize('view', $channel);

        $messages = $channel->rootMessages()
            ->visible()
            ->with(['author', 'reactions', 'quoted.author', 'pinner', 'media', 'workspace', 'workflow'])
            ->before($request->query('before'))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        // Clear this channel before building the sidebar: the member is looking
        // at the messages right now, so leaving its own badge on screen would
        // read as a bug rather than as information.
        $this->markChannelRead->handle($channel, $user);

        // Opening a conversation you had put away brings it back. Otherwise the
        // sidebar would leave out the very row you are reading.
        if ($channel->isDirect()) {
            $this->hideDirectMessage->reopen($channel, $user);
        }

        /*
         * Both halves have to hold: the workspace has to offer tickets and this
         * channel has to keep them. A channel that was keeping them when the
         * workspace switched them off still says it does, and the column is the
         * older of the two answers.
         */
        $keepsTickets = $workspace->hasFeature(Tickets::class) && $channel->hasTickets();

        // Same two halves, same reason: the workspace has to offer documents and
        // this channel has to keep them.
        $keepsDocuments = $workspace->hasFeature(DocumentsFeature::class) && $channel->hasDocuments();

        return Inertia::render('chat/show', [
            ...$this->buildChatShell->handle($workspace, $user),
            'channel' => [
                'id' => $channel->id,
                'type' => $channel->type->value,
                // How the conversation is drawn. Not the same question as the
                // type next to it, which says who may see it.
                'layout' => $channel->isFeed() ? 'feed' : 'chat',
                'name' => $channel->name,
                'label' => $channel->loadMissing('members')->displayNameFor($user),
                'topic' => $channel->topic,
                'memberCount' => $channel->members()->count(),
                'isMember' => $channel->members()->whereKey($user->id)->exists(),
                // Whether this member has asked for quiet here, and until when.
                // Their own decision and nobody else's, so it travels with the
                // channel rather than with its settings.
                'mutedUntil' => $this->mutedUntil($channel, $user),
                // This member's own override for instant push on this
                // channel — null when they never touched it, which is the
                // browser's cue to say "volgt je accountinstelling".
                'instantNotifications' => $channel->loadMissing('members')
                    ->membershipFor($user)?->instant_notifications,
                'isFavorite' => $channel->loadMissing('members')
                    ->membershipFor($user)?->favorited_at !== null,
                'postingPolicy' => $channel->posting_policy->value,
                // Whether threads are open here at all. Not the same question
                // as canReply below: this one is the channel's setting, that
                // one is this member against it.
                'repliesOpen' => $channel->replies_open,
                'canReply' => $user->can('reply', $channel),
                'ticketPolicy' => $channel->ticket_policy->value,
                'ticketAnnouncements' => $channel->ticket_announcements,
                'ticketStatusAnnouncements' => $channel->ticket_status_announcements,
                // Not the same question as the policy above: a DM never keeps
                // tickets, whatever the column happens to say.
                'hasTickets' => $channel->hasTickets(),
                'canCreateTicket' => $user->can('create', [Ticket::class, $channel]),
                'documentPolicy' => $channel->document_policy->value,
                'documentAnnouncements' => $channel->document_announcements,
                // Not the same question as the policy above: a DM never keeps
                // documents, whatever the column happens to say.
                'hasDocuments' => $channel->hasDocuments(),
                'canCreateDocument' => $user->can('create', [Document::class, $channel]),
                // Whether the composer opens at all. Reacting and answering in
                // a thread stay open even when this is false, so those are not
                // the same question.
                'canPost' => $user->can('post', $channel),
                'canManageSettings' => $user->can('manageSettings', $channel),
                // Asked apart from canManageSettings because it answers
                // differently on an archived channel, which may still be
                // deleted but no longer configured.
                'canDelete' => $user->can('deleteChannel', $channel),
                // The reversible neighbour of canDelete, and the same people.
                'canArchive' => $user->can('archiveChannel', $channel),
                // Whether the pin button appears on a message at all. The same
                // ability as managing the channel — pinning is editorial, see
                // MessagePolicy::pin() — but asked here as its own question, so
                // the browser never has to infer one from the other.
                'canPin' => $user->can('manageSettings', $channel),
                /*
                 * Whether the bin appears on a message a bot posted.
                 *
                 * Asked here rather than worked out in the browser, because the
                 * browser cannot: the answer is "you configure this channel, or
                 * your role holds the right, or you are a platform moderator",
                 * and only the first of those three is anywhere near the
                 * frontend. It also cannot travel on the message itself — that
                 * payload is the broadcast payload, one copy for everybody on
                 * the channel, and this differs per reader.
                 *
                 * MessagePolicy::delete is still what decides; this only says
                 * whether to draw the button, so a stale page can be refused
                 * rather than obeyed.
                 */
                'canDeleteBotMessages' => $user->isAdmin()
                    || $user->can('manageSettings', $channel)
                    || $workspace->allows($user, WorkspaceAbility::DeleteBotMessages),
                // Whether the member button at the top opens anything. False
                // for a guest, so for them it stays a presence indicator.
                'canViewMembers' => $user->can('viewMembers', $channel),
                'canAddMembers' => $user->can('addMembers', $channel),
                'canLeave' => $user->can('leave', $channel),
                'createdBy' => $channel->created_by,
                // The labels on this channel. Empty for a guest: a tag says how
                // the channel is filed internally — "klant", "escalatie" — and
                // that is not the customer's business. See BuildChatShell.
                'tags' => ! $workspace->isExternal($user)
                    ? $channel->tags->pluck('name')->all()
                    : [],
                // Drawn for everyone who can see the channel, guests included:
                // a link to the shared planning is exactly the kind of thing an
                // outside participant is in the channel for.
                'links' => $channel->links->map(fn (ChannelLink $link): array => [
                    'id' => $link->id,
                    'label' => $link->label,
                    // One of the two is always null — a button either goes
                    // somewhere or starts something. Both are sent so the bar
                    // can tell which kind it is drawing without a third field
                    // that could disagree with them.
                    'url' => $link->url,
                    'workflowId' => $link->workflow_id,
                    // The name rather than a lookup on the other side: the bar
                    // draws the label, but the settings panel has to say which
                    // workflow a button starts, and a member who may configure
                    // the channel is not necessarily allowed near the builder.
                    'workflowName' => $link->workflow?->name,
                ])->values()->all(),
                /*
                 * The workflows a button may be pointed at, for the panel that
                 * manages them. Only for whoever configures the channel: it is
                 * the one place they are drawn, and a list of everything a
                 * workspace automates is not something to hand to every guest
                 * reading along.
                 */
                /*
                 * The commands the message field answers to here, beside the
                 * handful it answers to itself. Everyone who may post gets
                 * them: a command is only worth offering to somebody who can
                 * use it, and the endpoint asks the same question again.
                 */
                /*
                 * The conversation going on in this channel right now, or null.
                 * Sent to everybody who may see the channel rather than only to
                 * those who may join: knowing that four colleagues are talking
                 * in here is what makes somebody walk in — see HuddlePolicy.
                 */
                'huddle' => $workspace->hasFeature(HuddlesFeature::class)
                    ? $this->huddle($channel, $user)
                    : null,
                /*
                 * What is still to come in this channel's diary. Beside the
                 * live huddle rather than inside it, because they are different
                 * things: one is a conversation you can walk into now, the
                 * other is an appointment nobody has arrived at yet.
                 *
                 * Sent to everybody who may see the channel, on the same
                 * reasoning as the live huddle above — knowing that there is a
                 * meeting at two is what makes somebody be there at two.
                 */
                'scheduledHuddles' => $workspace->hasFeature(HuddlesFeature::class)
                    ? $this->scheduledHuddles($channel, $user)
                    : [],
                'canHuddle' => $workspace->hasFeature(HuddlesFeature::class)
                    && $this->iceServers->configured()
                    && $user->can('join', [Huddle::class, $channel]),
                /*
                 * Where a browser should look to reach the others, credentials
                 * and all. Per request rather than in the bundle: half of it
                 * expires — see IceServers — and the other half is a
                 * deployment setting that must change without a rebuild.
                 *
                 * Only for somebody who may actually join: a signed relay
                 * credential is worth having, and there is no reason to hand
                 * one to a reader who cannot use it.
                 */
                'iceServers' => $workspace->hasFeature(HuddlesFeature::class)
                    && $user->can('join', [Huddle::class, $channel])
                        ? $this->iceServers->handle($user)
                        : [],
                'commands' => $user->can('post', $channel)
                    ? Workflow::query()
                        ->listeningFor($workspace, SlashCommandTrigger::key())
                        ->orderBy('name')
                        ->get()
                        ->map(fn (Workflow $workflow): array => [
                            'name' => SlashCommandTrigger::normalise(
                                (string) $workflow->triggerSetting('command', ''),
                            ),
                            'description' => $workflow->description ?? $workflow->name,
                        ])
                        // A workflow whose trigger was never filled in has no
                        // command to offer, and an empty one would draw a bare
                        // slash in the palette.
                        ->filter(fn (array $command): bool => $command['name'] !== '')
                        ->values()
                        ->all()
                    : [],
                'buttonWorkflows' => $user->can('manageSettings', $channel)
                    ? Workflow::query()
                        // The scope rather than the two conditions by hand: it
                        // is what every listener asks, and "off" meaning "still
                        // offered here" is exactly what it exists to prevent.
                        ->listeningFor($workspace, ButtonTrigger::key())
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn (Workflow $workflow): array => [
                            'id' => $workflow->id,
                            'name' => $workflow->name,
                        ])->all()
                    : [],
                // Feeds the composer's @-autocomplete and lets the renderer
                // tell a real mention apart from an email address.
                'members' => $this->channelMembers($workspace, $channel),
            ],
            'messages' => $this->presentMessage->collection($messages),
            /*
             * What this member alone was told here: the receipt for a command
             * they typed or a button they pressed. Fetched apart from the
             * messages and drawn under them, because they are not part of what
             * the channel said — see the ephemeral_notices migration.
             */
            'notices' => $channel->notices()
                ->where('user_id', $user->id)
                ->current()
                ->orderBy('id')
                ->get()
                ->map(fn (EphemeralNotice $notice): array => [
                    'id' => $notice->id,
                    'body' => $notice->body,
                    'authorName' => $notice->author_name,
                    'createdAt' => $notice->created_at?->toIso8601String(),
                ])->all(),
            /*
             * Which of these this member set aside — beside the messages rather
             * than inside them, and deliberately so: the message payload is
             * also what gets broadcast to everyone in the channel, and a
             * "saved" flag in there would be one person's answer sent to all of
             * them.
             */
            'bookmarkedIds' => Bookmark::query()
                ->where('user_id', $user->id)
                ->whereIn('message_id', $messages->pluck('id'))
                ->pluck('message_id')
                ->all(),
            // Their own list rather than something read off the messages above:
            // the page loads the last fifty messages, and a channel intro
            // pinned months ago is not among them — which is precisely why it
            // was pinned.
            'pins' => $this->pins($channel),
            'scheduled' => $this->scheduled($channel, $user),
            'thread' => $this->thread($channel, $user, $request->query('thread')),
            // The board is a second view of this channel rather than a page of
            // its own: it needs the same sidebar, the same unread counts and the
            // same live connection, and duplicating that shell is how the two
            // would drift apart.
            'tickets' => $keepsTickets ? $this->tickets($channel, $user) : null,
            'ticket' => $keepsTickets
                ? $this->ticket($channel, $user, $request->query('ticket'))
                : null,
            // Which of the two views the channel opens in. Decided here rather
            // than read off the URL in the browser, so a channel that stopped
            // keeping tickets — or a workspace that switched them off
            // altogether — cannot be left showing a board through a stale link.
            // The channel's documents, and the one that is open. Second view of
            // the same page for the same reason the board is.
            'documentList' => $keepsDocuments ? $this->documentList($channel, $user) : null,
            'openDocument' => $keepsDocuments
                ? $this->document($channel, $user, $request->query('document'))
                : null,
            // Which of the three views the channel opens in. Decided here rather
            // than read off the URL in the browser, so a channel that stopped
            // keeping tickets or documents — or a workspace that switched them
            // off altogether — cannot be left showing one through a stale link.
            'view' => match ($request->query('view')) {
                'tickets' => $keepsTickets ? 'tickets' : 'messages',
                'documents' => $keepsDocuments ? 'documents' : 'messages',
                default => 'messages',
            },
        ]);
    }

    /**
     * What is pinned in this channel, oldest pin first.
     *
     * No ability check of its own: whoever may see the channel may read what is
     * pinned in it. Pinning is the ability that is restricted — reading the
     * rules cannot be.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pins(Channel $channel): array
    {
        $pinned = $channel->messages()
            ->pinned()
            ->with(['author', 'pinner'])
            ->get();

        return $this->presentMessage->pins($pinned);
    }

    /**
     * What this member still has waiting in this channel, soonest first.
     *
     * Their own only, and never anybody else's: a scheduled message has not
     * been said yet, so there is nothing for a channel admin to moderate —
     * only somebody's draft to leave alone.
     *
     * Failed ones stay in the list. A message that silently never arrived is
     * worse than one that says why, and the author is the only person who can
     * do anything about it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduled(Channel $channel, User $user): array
    {
        return $channel->scheduledMessages()
            ->where('user_id', $user->id)
            ->whereNull('sent_at')
            ->orderBy('send_at')
            ->get()
            ->map(fn (ScheduledMessage $scheduled): array => [
                'id' => $scheduled->id,
                'body' => $scheduled->body,
                'sendAt' => $scheduled->send_at->toIso8601String(),
                'failedAt' => $scheduled->failed_at?->toIso8601String(),
                'failureReason' => $scheduled->failure_reason,
            ])->all();
    }

    /**
     * The channel's tickets, plus a count per status.
     *
     * Null when the channel keeps none, so the page can tell "no tickets yet"
     * apart from "this channel does not do tickets" without a second flag.
     *
     * The counts are their own query rather than a tally of the rows above: the
     * board shows at most a page of tickets, and a header that counts only what
     * happens to be loaded is a header that quietly lies as soon as a channel
     * gets busy.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}|null
     */
    private function tickets(Channel $channel, User $user): ?array
    {
        if (! $user->can('viewBoard', [Ticket::class, $channel])) {
            return null;
        }

        $rows = $channel->tickets()
            ->with(['opener', 'assignee'])
            ->withCount('comments')
            ->inBoardOrder()
            ->limit(100)
            ->get();

        return [
            'rows' => $this->presentTicket->collection($rows),
            'counts' => $channel->tickets()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ];
    }

    /**
     * The channel's documents, most recently worked on first.
     *
     * Without their documents — see PresentDocument::summary(). A channel with a
     * dozen documents would otherwise ship a dozen JSON trees to draw a list of
     * titles, and the one that is open is fetched separately anyway.
     *
     * No limit, unlike the ticket board's hundred. Documents are made
     * deliberately and a channel has a handful, not a backlog; a cut-off here
     * would hide a document rather than trim a queue.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function documentList(Channel $channel, User $user): ?array
    {
        if (! $user->can('viewList', [Document::class, $channel])) {
            return null;
        }

        $documents = $channel->documents()->with(['creator', 'editor'])->get();

        return $this->presentDocument->list($documents);
    }

    /**
     * The document that is open, or null when the query string names none.
     *
     * Addressed by its number rather than its id, the same way tickets are and
     * the same way people write it down — see Document::getRouteKeyName().
     *
     * @return array<string, mixed>|null
     */
    private function document(Channel $channel, User $user, ?string $number): ?array
    {
        if ($number === null || ! $user->can('viewList', [Document::class, $channel])) {
            return null;
        }

        $document = $channel->documents()->where('number', $number)->first();

        return $document === null ? null : $this->presentDocument->handle($document, $user);
    }

    /**
     * The huddle going on in this channel, with who is in it, or null.
     *
     * The same shape the broadcast carries — see HuddleUpdated — so the browser
     * has one thing to read whether it arrived with the page or over the
     * socket, and no code that has to reconcile two spellings of it.
     *
     * @return array<string, mixed>|null
     */
    private function huddle(Channel $channel, User $user): ?array
    {
        if ($user->cannot('see', [Huddle::class, $channel])) {
            return null;
        }

        $huddle = $channel->huddles()->live()
            ->with(['present.user:id,name', 'recorder:id,name'])
            ->first();

        return $huddle === null ? null : [
            'id' => $huddle->id,
            'channelId' => $huddle->channel_id,
            'live' => true,
            // Somebody walking in halfway through learns it here rather than
            // from the broadcast they were not subscribed to yet.
            'recordingBy' => $huddle->isBeingRecorded()
                ? ['id' => $huddle->recording_by, 'name' => $huddle->recorder?->name]
                : null,
            'participants' => $huddle->present
                ->map(fn (HuddleParticipant $participant): array => [
                    'id' => $participant->user_id,
                    'name' => $participant->user?->name,
                ])->values()->all(),
        ];
    }

    /**
     * The appointments this channel still has coming, soonest first.
     *
     * Whether each one is this member's to call off is worked out here rather
     * than in the browser: it is the same pair of questions the endpoint asks —
     * did you arrange it, or do you run the channel — and a screen that guessed
     * would offer a button that then refuses.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduledHuddles(Channel $channel, User $user): array
    {
        if ($user->cannot('see', [Huddle::class, $channel])) {
            return [];
        }

        return ScheduledHuddle::query()
            ->upcomingIn($channel)
            ->with('invitees:id,name')
            ->get()
            ->map(fn (ScheduledHuddle $scheduled): array => [
                'id' => $scheduled->id,
                'title' => $scheduled->title,
                'startsAt' => $scheduled->starts_at->toIso8601String(),
                'durationMinutes' => $scheduled->duration_minutes,
                'invitees' => $scheduled->invitees
                    ->map(fn (User $invitee): array => [
                        'id' => $invitee->id,
                        'name' => $invitee->name,
                    ])->values()->all(),
                'canCancel' => $scheduled->created_by === $user->id
                    || $user->can('manageSettings', $channel),
            ])
            ->all();
    }

    /**
     * The open ticket, or null when the query string names none.
     *
     * Addressed by its number rather than its id, the same way people talk
     * about it — see Ticket::getRouteKeyName().
     *
     * @return array<string, mixed>|null
     */
    private function ticket(Channel $channel, User $user, ?string $number): ?array
    {
        if ($number === null || ! $user->can('viewBoard', [Ticket::class, $channel])) {
            return null;
        }

        $ticket = $channel->tickets()->where('number', $number)->first();

        return $ticket === null ? null : [
            ...$this->presentTicket->handle($ticket),
            'canManage' => $user->can('manage', $ticket),
            'canConfirm' => $user->can('confirm', $ticket),
            'canEdit' => $user->can('update', $ticket),
            'canDelete' => $user->can('delete', $ticket),
        ];
    }

    /**
     * When this member's mute on this channel runs out.
     *
     * Three answers in one field: null for a channel that is not muted, the
     * string 'forever' for a mute with no end, and a moment for one that has.
     * The browser only ever draws one of the three, and folding them into one
     * field keeps it from having to combine two nullable ones and get the
     * "muted but expired" case subtly wrong.
     */
    private function mutedUntil(Channel $channel, User $user): ?string
    {
        $membership = $channel->loadMissing('members')->membershipFor($user);

        if ($membership === null || ! $membership->isMuted()) {
            return null;
        }

        return $membership->muted_until?->toIso8601String() ?? 'forever';
    }

    /**
     * The channel's members, each marked as a guest or not.
     *
     * Scoped to this channel, which is what keeps it safe to hand to a guest:
     * the payload feeds the @-autocomplete, so anything workspace-wide in here
     * would undo the isolation the guest role exists for.
     *
     * The guest ids come from one query for the whole channel rather than a
     * role lookup per member.
     *
     * The status travels with the member rather than with each message: a
     * status is what somebody is doing now, while a message is a record of a
     * moment. Baked into the message payload it would freeze — every line from
     * this morning still saying "in vergadering" long after the meeting ended.
     *
     * @return array<int, array{id: int, name: string, username: string, isGuest: bool, statusEmoji: string|null, statusText: string|null, availability: string}>
     */
    private function channelMembers(Workspace $workspace, Channel $channel): array
    {
        $guestIds = $workspace->externalMembers()
            ->pluck('users.id')
            ->flip();

        return $channel->members
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'avatarUrl' => $member->avatarUrl(),
                'isGuest' => $guestIds->has($member->id),
                'statusEmoji' => $member->status_emoji,
                'statusText' => $member->status_text,
                'availability' => $member->availability->value,
            ])->values()->all();
    }

    /**
     * The open thread, or null when the query string names none.
     *
     * Putting the thread in the URL rather than in component state means a
     * thread is linkable and survives a refresh — the same reason Slack does it.
     *
     * @return array{parent: array<string, mixed>, replies: array<int, array<string, mixed>>}|null
     */
    private function thread(Channel $channel, User $user, ?string $parentId): ?array
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $channel->rootMessages()
            ->visible()
            ->with(['author', 'reactions', 'quoted.author', 'pinner', 'media', 'workspace', 'workflow'])
            ->whereKey($parentId)
            ->first();

        if ($parent === null) {
            return null;
        }

        $replies = $parent->replies()
            ->with(['author', 'reactions', 'quoted.author', 'pinner', 'media', 'workspace', 'workflow'])
            ->orderBy('id')
            ->get();

        return [
            'parent' => $this->presentMessage->handle($parent),
            'replies' => $this->presentMessage->collection($replies),
        ];
    }

    private function authorizeMembership(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->hasMember($user), 403, __('chat.not_a_member'));
    }
}
