<?php

namespace App\Actions\Chat;

use App\Enums\AttachmentType;
use App\Features\Huddles as HuddlesFeature;
use App\Models\BoardPost;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\CustomEmoji;
use App\Models\HuddleParticipant;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Role;
use App\Models\ScheduledBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the chat screen has around whatever it is showing: the workspace
 * header, the channel list, the direct messages and the active threads.
 *
 * Pulled out of ChatController once a second page needed it. The workspace-wide
 * ticket view is not a channel, but it lives inside the same shell — the same
 * sidebar, the same unread badges, the same live connection — and two
 * controllers each building that shell their own way is how the badges on one
 * page start disagreeing with the badges on the other.
 */
class BuildChatShell
{
    public function __construct(
        private readonly CountUnread $countUnread,
        private readonly FindActiveThreads $findActiveThreads,
        private readonly PresentMessage $presentMessage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace, User $user): array
    {
        $channels = $this->visibleChannels($workspace, $user);

        /*
         * Tags are internal. They label channels for the people running the
         * workspace — "klant", "intern", "escalatie" — and a customer sitting
         * in one of them has no business reading how their channel is filed.
         * Nothing downstream has to know: with an empty list the sidebar filter
         * disappears, the chips in the header draw nothing, and the broadcast
         * dialog offers no tags.
         */
        $seesTags = ! $workspace->isExternal($user);

        return [
            'workspace' => $this->workspace($workspace, $user),
            ...$this->channels($channels, $user, $seesTags),
            /*
             * What is waiting in the inbox, of every kind — not just mentions.
             * The badges beside the channel names are narrower on purpose: a
             * number there reads as "somebody asked you something", while the
             * one on the inbox button stands for the whole list behind it.
             */
            /*
             * The other workspaces this member belongs to, so the menu at the
             * top of the sidebar can move them between teams. Only what a menu
             * row needs — a switch is a redirect, and the destination builds
             * its own shell from scratch.
             *
             * Ordered by when they joined, which is the same order chat.home
             * uses to decide where somebody lands with no workspace in the URL.
             * Two lists in different orders would make "the first one" mean two
             * things.
             */
            'workspaces' => $user->workspaces()
                ->oldest('workspace_user.joined_at')
                // Two workspaces joined in the same second are otherwise in
                // whatever order the database felt like, and a menu that
                // reshuffles between page loads is one people stop being able
                // to point at.
                ->orderBy('workspaces.id')
                ->get()
                ->map(fn (Workspace $other): array => [
                    'id' => $other->id,
                    'name' => $other->name,
                    'slug' => $other->slug,
                    'avatarUrl' => $other->avatarUrl(),
                    'isCurrent' => $other->id === $workspace->id,
                ])
                ->all(),

            /*
             * Announcements this member has waiting, for the dialog that made
             * them. Only their own and only the pending ones: somebody checking
             * "did I schedule that?" is asking about what can still be stopped.
             *
             * In the shell rather than on a page of its own, because a
             * scheduled broadcast belongs to no channel — the one thing that
             * makes it not a scheduled message.
             */
            'scheduledBroadcasts' => ScheduledBroadcast::query()
                ->where('workspace_id', $workspace->id)
                ->where('created_by', $user->id)
                ->pending()
                ->with('channels:id,name,type')
                ->orderBy('send_at')
                ->get()
                ->map(fn (ScheduledBroadcast $broadcast): array => [
                    'id' => $broadcast->id,
                    'body' => $broadcast->body,
                    'sendAt' => $broadcast->send_at->toIso8601String(),
                    'channels' => $broadcast->channels
                        ->map(fn (Channel $channel): string => $channel->displayNameFor($user))
                        ->all(),
                ])
                ->all(),

            'inboxUnread' => InboxItem::query()
                ->where('user_id', $user->id)
                ->whereIn('channel_id', $channels->pluck('id'))
                ->unread()
                ->count(),
            /*
             * Every tag on a channel this member can see — for the sidebar
             * filter, and for the settings dialog to suggest from.
             *
             * Derived from what they can see rather than read off the
             * workspace: even for a member, a list of every label in use would
             * tell them which subjects exist behind doors they cannot open.
             * Typing a label that already exists elsewhere still reuses it —
             * see ChannelTag::claim — so nothing is duplicated by hiding it.
             */
            'workspaceTags' => ! $seesTags ? [] : $channels
                ->flatMap(fn (Channel $channel) => $channel->tags->pluck('name'))
                ->unique()
                ->sort()
                ->values()
                ->all(),
            // Hung under their own channel in the sidebar, so a lively thread
            // stays visible even while the channel it lives in is scrolled past.
            'activeThreads' => $this->findActiveThreads->handle($user, $workspace)
                ->map(fn (Message $thread): array => [
                    ...$this->presentMessage->threadSummary($thread),
                    /*
                     * Whether this member asked to stop hearing about it. The
                     * thread is still listed — muting is about the inbox, not
                     * about hiding things — so the sidebar needs to know in
                     * order to offer the way back rather than the way in.
                     */
                    'muted' => (bool) $thread->muted,
                ])
                ->all(),
            /*
             * The channels that were put away, for whoever may take them back
             * out. Empty for everybody else, so the sidebar has nothing to
             * draw: an archived channel is meant to be out of the way, and a
             * section listing them for people who cannot reopen them would be
             * clutter that never becomes useful.
             */
            'archivedChannels' => $this->archivedChannels($workspace, $user),
            /*
             * The groups this member arranged for themselves, and which channel
             * sits in which. Sent as a list of sections with channel ids rather
             * than as a field on each channel: the sidebar draws them as
             * headings, and a section with nothing in it still has to appear —
             * otherwise a group somebody just made looks like it failed.
             */
            'sections' => $this->sections($workspace, $user),
            /*
             * Everybody in the workspace, for the panel beside the conversation.
             * Empty when this member does not get the panel, so there is nothing
             * to draw rather than a list held back by the browser.
             */
            'workspaceMembers' => $workspace->member_panel->allows($workspace->roleFor($user))
                ? $this->workspaceMembers($workspace)
                : [],
        ];
    }

    /**
     * The people in the workspace, by name.
     *
     * Guests are left out. They are in the workspace for the channels they were
     * invited to rather than for the workspace itself — the line
     * canBrowseWorkspace draws everywhere else — and a directory of every
     * customer in every channel is not what "wie zit hier" was asking for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workspaceMembers(Workspace $workspace): array
    {
        return $workspace->internalMembers()
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'avatarUrl' => $member->avatarUrl(),
                // Always false, and present anyway: this is the same shape the
                // channel member list uses, and one list quietly missing a
                // field is how a shared row component starts needing two.
                'isGuest' => false,
                'statusEmoji' => $member->status_emoji,
                'statusText' => $member->status_text,
                'availability' => $member->availability->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function workspace(Workspace $workspace, User $user): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            // The logo beside the name in the sidebar, or null when none is
            // set — then the first letter stands in.
            'avatarUrl' => $workspace->avatarUrl(),
            // Drives whether the composer offers @here and @everyone at all:
            // better to hide them than to let someone pick one and have it
            // quietly notify nobody.
            'canBroadcastMention' => $user->can('broadcastMention', $workspace),
            'canManage' => $user->can('manage', $workspace),
            'canInvite' => $user->can('invite', $workspace),

            /*
             * The roles this member may hand out, for the invite dialog. From
             * the workspace rather than a pair of words in the browser: a
             * workspace writes its own roles, so "gast of lid" is no longer the
             * whole list — and which of them somebody may give away is a
             * question only the policy can answer.
             */
            'invitableRoles' => $workspace->roles()->get()
                ->filter(fn (Role $role): bool => $user->can('grantRole', [$workspace, $role]))
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'isExternal' => $role->is_external,
                ])->values()->all(),
            // Hides "Kanaal toevoegen" for a guest. The request checks the same
            // ability, so this only spares them a button that would have
            // refused them.
            'canCreateChannel' => $user->can('createChannel', $workspace),
            // Unlike canCreateChannel this stays true for a guest: they may
            // write to the people in their own channels. Who those are is
            // decided by the candidate search, not here.
            'canStartDirectMessage' => $user->can('startDirectMessage', $workspace),
            // Whether the "Rondsturen" entry appears at all. False for a guest,
            // who is here for their own channels rather than for the workspace.
            'canBroadcastToChannels' => $user->can('broadcastToChannels', $workspace),

            /*
             * Which parts of the product this workspace offers.
             *
             * Beside the can* abilities rather than mixed in with them, because
             * they answer different questions: those are about this member,
             * these are about the room. A button needs both to be true — you
             * may do it, and it exists here.
             */
            'features' => $this->features($workspace),

            /*
             * Whether the member list beside the conversation appears at all.
             * One answer, worked out here: the setting has three states and a
             * guest is outside all of them, and the browser should not be the
             * place where a role gets interpreted.
             */
            'showsMemberPanel' => $workspace->member_panel->allows(
                $workspace->roleFor($user),
            ),

            /*
             * Whether the composer shows a paperclip at all, and what it lets
             * through before the server gets a say.
             *
             * Null when sharing is off, so the browser has nothing to draw
             * rather than a set of limits that would be refused anyway. The
             * accept list is a hint for the file dialog — the endpoint decides
             * for real, on the file's own bytes.
             */
            'uploads' => $workspace->uploads_enabled ? [
                'maxKb' => $workspace->max_attachment_kb,
                'accept' => implode(',', array_map(
                    fn (string $mimeType): string => str_ends_with($mimeType, '/')
                        ? $mimeType.'*'
                        : $mimeType,
                    array_merge(...array_map(
                        fn (AttachmentType $type): array => $type->mimeTypes(),
                        $workspace->allowedAttachmentTypes(),
                    )),
                )),
            ] : null,

            /*
             * What the message field needs to offer sending files by link, or
             * null when it must not offer it at all. Both questions in one
             * value — the workspace has to have the feature and this member has
             * to be allowed to use it — so the composer cannot draw a button
             * that the endpoint would refuse.
             */
            'transfers' => $user->can('createTransfer', $workspace) ? [
                'maxKb' => $workspace->max_transfer_kb,
                'maxDays' => $workspace->max_transfer_days,
            ] : null,

            /*
             * Whether the message field may offer to ask somebody for a
             * password or a key. Same shape and same reasoning as transfers
             * above: one value answering both "does this workspace have it" and
             * "may this member use it", so the composer cannot draw a button
             * the endpoint would refuse.
             */
            'secrets' => $user->can('createSecretRequest', $workspace),

            /** Whether the message field may offer to put a question to the channel. */
            'polls' => $user->can('createPoll', $workspace),

            /*
             * Whether the rail offers the forms screen.
             *
             * The same one-value shape as secrets above: the workspace has the
             * feature and this role may write a form, answered together so the
             * rail cannot draw a button leading to a 404.
             */
            'forms' => $user->can('createForm', $workspace),

            /*
             * Whether the rail offers the clock.
             *
             * The same one-value shape as forms above, and it carries the guest
             * rule with it: WorkspacePolicy::clock says no to somebody who is
             * here from outside, so the rail cannot offer a clock to a person
             * whose hours are another company's business.
             */
            'timeclock' => $user->can('clock', $workspace),

            /*
             * Whether the prikbord appears in the rail at all.
             *
             * One value rather than letting the rail read features['message-board']
             * and work the rest out. It has to be false for a guest as well as
             * for a workspace with the board switched off, and a guest is
             * precisely the reader who must not be handed the two halves and
             * trusted to combine them.
             */
            'board' => $user->can('viewAny', [BoardPost::class, $workspace]),

            /*
             * The pictures this workspace named for itself.
             *
             * In the shell rather than fetched by the picker, because three
             * separate things need the same list: the picker offers them, the
             * composer suggests them while you type, and every message drawn on
             * screen has to turn ":shipit:" back into a picture. A list that
             * arrived later would mean a screenful of chat that reads as bare
             * text for a moment and then rearranges itself.
             *
             * Small enough to send whole — a name and a URL each, capped at two
             * hundred by the screen that makes them.
             */
            'customEmoji' => $workspace->customEmoji()->get()
                ->map(fn (CustomEmoji $emoji): array => [
                    'name' => $emoji->name,
                    'url' => $emoji->url(),
                ])
                ->all(),
        ];
    }

    /**
     * The feature switches under the short names the routes use, so the button
     * and the endpoint that refuses it are recognisably the same thing.
     *
     * @return array<string, bool>
     */
    private function features(Workspace $workspace): array
    {
        $states = $workspace->featureStates();

        $named = [];

        foreach ($states as $feature => $active) {
            $named[$feature::key()] = $active;
        }

        return $named;
    }

    /**
     * The sidebar splits channels from DMs, which is purely presentational:
     * both are rows in the channels table.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array{channels: array<int, array<string, mixed>>, directMessages: array<int, array<string, mixed>>}
     */
    private function channels($channels, User $user, bool $seesTags): array
    {
        ['unread' => $unread, 'mentions' => $mentions] = $this->countUnread
            ->handle($user, $channels->pluck('id'));

        // One query for the whole sidebar rather than one per row. Not filtered
        // by the ticket policy: a channel that stopped keeping tickets still has
        // to show the ones already open, or switching the setting off would hide
        // outstanding work.
        $openTickets = Ticket::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->open()
            ->selectRaw('channel_id, count(*) as total')
            ->groupBy('channel_id')
            ->pluck('total', 'channel_id');

        /*
         * Which channels have somebody talking in them, and how many. One query
         * for the whole sidebar rather than one per row, the same way the
         * ticket counts above are taken — a badge is not worth a query each.
         *
         * Only where the workspace has huddles at all; elsewhere this is an
         * empty list and every row reads zero. The workspace is read off the
         * rows themselves — they are all of one, and this method is handed
         * channels rather than the workspace they belong to.
         */
        $huddling = $channels->first()?->workspace?->hasFeature(HuddlesFeature::class)
            ? HuddleParticipant::query()
                ->join('huddles', 'huddles.id', '=', 'huddle_participants.huddle_id')
                ->whereIn('huddles.channel_id', $channels->pluck('id'))
                ->whereNull('huddles.ended_at')
                ->whereNull('huddle_participants.left_at')
                ->selectRaw('huddles.channel_id, count(*) as total')
                ->groupBy('huddles.channel_id')
                ->pluck('total', 'channel_id')
            : collect();

        $present = fn (Channel $channel): array => [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'name' => $channel->name,
            'label' => $channel->displayNameFor($user),
            'isMember' => $channel->members->contains($user),
            // Quiet for this member, and until when. Read off the loaded
            // membership rather than queried per row: the sidebar draws every
            // channel this member is in, and that is a query each.
            'mutedUntil' => $this->mutedUntil($channel, $user),
            // Whether this member keeps it at the top of their own sidebar.
            'isFavorite' => $channel->membershipFor($user)?->favorited_at !== null,
            'unreadCount' => $unread[$channel->id] ?? 0,
            'mentionCount' => $mentions[$channel->id] ?? 0,
            'openTicketCount' => $openTickets[$channel->id] ?? 0,
            // Whether this channel keeps tickets at all — which is what decides
            // whether the sidebar offers the workspace-wide list. Not the same
            // question as the count above: a channel can keep tickets and have
            // none outstanding.
            'hasTickets' => $channel->hasTickets(),
            /*
             * How many people are talking in here right now, zero for the rest.
             * A count rather than names: the sidebar has a row's width, and
             * what it has to say is "er gebeurt hier iets" — the names are in
             * the channel itself.
             */
            'huddleCount' => (int) ($huddling[$channel->id] ?? 0),
            // What the sidebar filters on. Empty for a guest, and for a DM,
            // which never carries any — absent would make every reader of this
            // payload check for it.
            'tags' => $seesTags ? $channel->tags->pluck('name')->all() : [],
            // Only for a one-on-one, where the row stands for a person and
            // their status is as much a part of them as their name. A channel
            // is a room; a room has no status.
            'status' => $this->directStatus($channel, $user),
        ];

        return [
            'channels' => $channels
                ->reject(fn (Channel $channel) => $channel->isDirect())
                ->map($present)->values()->all(),
            'directMessages' => $channels
                ->filter(fn (Channel $channel) => $channel->isDirect())
                ->map($present)->values()->all(),
        ];
    }

    /**
     * The channels this member has in their sidebar.
     *
     * Public because the ticket view needs the same set to scope itself to: a
     * ticket is exactly as visible as the channel it sits in, and asking that
     * question twice in two different ways is how a guest ends up seeing one
     * they should not.
     *
     * @return Collection<int, Channel>
     */
    public function visibleChannels(Workspace $workspace, User $user)
    {
        return $workspace->channels()
            ->visibleTo($user)
            ->notHiddenBy($user)
            ->whereNull('archived_at')
            ->with(['members', 'tags'])
            ->orderBy('name')
            ->get();
    }

    /**
     * The groups this member made in their own sidebar.
     *
     * Channel ids only: the rows themselves are already in the channel list
     * above, and sending them twice would be two copies of an unread count that
     * could disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(Workspace $workspace, User $user): array
    {
        return ChannelSection::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->with('channels:id')
            ->inOrder()
            ->get()
            ->map(fn (ChannelSection $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'channelIds' => $section->channels->pluck('id')->all(),
            ])
            ->all();
    }

    /**
     * The channels this member put away and may take back out.
     *
     * Read with the same visibility rules as the live list — an archived
     * private channel is still private — and then narrowed to the ones this
     * member answers for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function archivedChannels(Workspace $workspace, User $user): array
    {
        return $workspace->channels()
            ->visibleTo($user)
            ->whereNotNull('archived_at')
            ->with('members')
            ->orderBy('name')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('archiveChannel', $channel))
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'label' => $channel->displayNameFor($user),
                'archivedAt' => $channel->archived_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * When this member's mute on this channel runs out, or null when it is not
     * muted. The string 'forever' stands for a mute with no end.
     */
    private function mutedUntil(Channel $channel, User $user): ?string
    {
        $membership = $channel->membershipFor($user);

        if ($membership === null || ! $membership->isMuted()) {
            return null;
        }

        return $membership->muted_until?->toIso8601String() ?? 'forever';
    }

    /**
     * The other person's status, for a sidebar row that is a conversation with
     * exactly one of them.
     *
     * Null for a channel, for a note to self, and for a group DM: with more
     * than one person on the other side there is no single status to show, and
     * picking one of them would be arbitrary.
     *
     * The person's id travels along: a status that changes while the page is
     * open arrives over the socket addressed by user, and without it the
     * browser has no way to tell whose row to update.
     *
     * @return array{userId: int, emoji: string|null, text: string|null, availability: string}|null
     */
    private function directStatus(Channel $channel, User $viewer): ?array
    {
        if (! $channel->isDirect()) {
            return null;
        }

        $others = $channel->members->reject(fn (User $member) => $member->is($viewer));

        if ($others->count() !== 1) {
            return null;
        }

        $other = $others->first();

        return [
            'userId' => $other->id,
            'emoji' => $other->status_emoji,
            'text' => $other->status_text,
            'availability' => $other->availability->value,
        ];
    }
}
