<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
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
            'messages' => $this->presentMessages($messages, $user),
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

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function presentMessages(Collection $messages, User $user): array
    {
        return $messages->map(fn (Message $message): array => [
            'id' => $message->id,
            'body' => $message->body,
            'createdAt' => $message->created_at?->toIso8601String(),
            'editedAt' => $message->edited_at?->toIso8601String(),
            'replyCount' => $message->reply_count,
            'author' => [
                'id' => $message->author->id,
                'name' => $message->author->name,
            ],
            'reactions' => $message->reactions
                ->groupBy('emoji')
                ->map(fn (Collection $group, string $emoji): array => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'reacted' => $group->contains('user_id', $user->id),
                ])->values()->all(),
        ])->all();
    }

    private function authorizeMembership(User $user, Workspace $workspace): void
    {
        abort_unless($workspace->hasMember($user), 403, 'Je bent geen lid van deze workspace.');
    }
}
