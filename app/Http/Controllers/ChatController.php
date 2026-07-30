<?php

namespace App\Http\Controllers;

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
    public function __construct(private readonly PresentMessage $presentMessage) {}

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
            ],
            'messages' => $this->presentMessage->collection($messages, $user),
        ]);
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

        $present = fn (Channel $channel): array => [
            'id' => $channel->id,
            'type' => $channel->type->value,
            'name' => $channel->name,
            'label' => $channel->displayNameFor($user),
            'isMember' => $channel->members->contains($user),
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
