<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CountUnread;
use App\Actions\Chat\MarkChannelRead;
use App\Actions\Chat\PresentMessage;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(
        private readonly PresentMessage $presentMessage,
        private readonly CountUnread $countUnread,
        private readonly MarkChannelRead $markChannelRead,
    ) {}

    /**
     * The landing page after signing in. Sends the member to their workspace;
     * once a member can belong to several, this is where the picker goes.
     */
    public function home(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->oldest('workspace_user.joined_at')->first();

        abort_if($workspace === null, 404, 'Je hoort nog bij geen enkele workspace.');

        return redirect()->route('chat.index', $workspace);
    }

    /**
     * Drop the member into the most recently active channel they can see.
     */
    public function index(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeMembership($request->user(), $workspace);

        $channel = $workspace->channels()
            ->visibleTo($request->user())
            ->whereNull('archived_at')
            ->orderByRaw('last_message_at desc nulls last')
            ->firstOrFail();

        return redirect()->route('chat.show', [$workspace, $channel]);
    }

    public function show(Request $request, Workspace $workspace, Channel $channel): Response
    {
        $user = $request->user();

        $this->authorizeMembership($user, $workspace);
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('view', $channel);

        $messages = $channel->rootMessages()
            ->with(['author', 'reactions'])
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

        return Inertia::render('chat/show', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            ...$this->sidebarChannels($workspace, $user),
            'channel' => [
                'id' => $channel->id,
                'type' => $channel->type->value,
                'name' => $channel->name,
                'label' => $channel->loadMissing('members')->displayNameFor($user),
                'topic' => $channel->topic,
                'memberCount' => $channel->members()->count(),
                'isMember' => $channel->members()->whereKey($user->id)->exists(),
                // Feeds the composer's @-autocomplete and lets the renderer
                // tell a real mention apart from an email address.
                'members' => $channel->members
                    ->map(fn (User $member): array => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'username' => $member->username,
                    ])->values()->all(),
            ],
            'messages' => $this->presentMessage->collection($messages, $user),
            'thread' => $this->thread($channel, $user, $request->query('thread')),
        ]);
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
            ->with(['author', 'reactions'])
            ->whereKey($parentId)
            ->first();

        if ($parent === null) {
            return null;
        }

        $replies = $parent->replies()
            ->with(['author', 'reactions'])
            ->orderBy('id')
            ->get();

        return [
            'parent' => $this->presentMessage->handle($parent, $user),
            'replies' => $this->presentMessage->collection($replies, $user),
        ];
    }

    /**
     * The sidebar splits channels from DMs, which is purely presentational:
     * both are rows in the channels table.
     *
     * @return array{channels: array<int, array<string, mixed>>, directMessages: array<int, array<string, mixed>>}
     */
    private function sidebarChannels(Workspace $workspace, User $user): array
    {
        $channels = $workspace->channels()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->with('members')
            ->orderBy('name')
            ->get();

        ['unread' => $unread, 'mentions' => $mentions] = $this->countUnread
            ->handle($user, $channels->pluck('id'));

        $present = fn (Channel $channel): array => [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'name' => $channel->name,
            'label' => $channel->displayNameFor($user),
            'isMember' => $channel->members->contains($user),
            'unreadCount' => $unread[$channel->id] ?? 0,
            'mentionCount' => $mentions[$channel->id] ?? 0,
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

    private function authorizeMembership(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->hasMember($user), 403, 'Je bent geen lid van deze workspace.');
    }
}
