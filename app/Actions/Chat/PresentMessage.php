<?php

namespace App\Actions\Chat;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class PresentMessage
{
    /**
     * Shape a message for the frontend.
     *
     * Both the Inertia page and the broadcast payload go through here, because
     * the browser merges the two into one list: if the shapes drift apart, a
     * message changes appearance the moment the page reloads.
     *
     * @return array<string, mixed>
     */
    public function handle(Message $message, ?User $viewer = null): array
    {
        $message->loadMissing(['author', 'reactions']);

        return [
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
                    'reacted' => $viewer !== null && $group->contains('user_id', $viewer->id),
                ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $messages, ?User $viewer = null): array
    {
        return $messages->map(fn (Message $message) => $this->handle($message, $viewer))->all();
    }
}
