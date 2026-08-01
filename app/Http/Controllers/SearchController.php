<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CensorBlockedWords;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * Full-text search over the workspace, scoped to the channels the member is
     * allowed to read. The workspace filter is not optional: it is the tenant
     * boundary, and a missing one here would leak another team's messages.
     */
    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $terms = $this->searchable($request->string('q')->trim()->value(), $workspace, $user);

        if ($terms === '') {
            return response()->json(['results' => []]);
        }

        $visibleChannelIds = $workspace->channels()
            ->visibleTo($user)
            ->pluck('id');

        $results = Message::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('channel_id', $visibleChannelIds)
            ->matching($terms)
            ->with(['author:id,name', 'channel:id,name,type'])
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                // Search builds its own payload rather than going through
                // PresentMessage, so the blocklist has to be applied here too —
                // otherwise a blocked word is one search away from readable.
                'body' => $this->censorBlockedWords->handle($message->body, $workspace->blocked_words),
                'createdAt' => $message->created_at?->toIso8601String(),
                'author' => $message->isFromBot()
                    ? $message->bot_name
                    : $message->author->name,
                'authorIsBot' => $message->isFromBot(),
                // A hit inside a thread has to open that thread, not just the
                // channel it hangs under: replies are not in the channel's own
                // message list, so landing there would show the searcher a
                // conversation their result is nowhere to be found in.
                'threadId' => $message->parent_id,
                'channel' => [
                    'id' => $message->channel->id,
                    'name' => $message->channel->name,
                    'type' => $message->channel->type->value,
                ],
            ]);

        return response()->json(['results' => $results]);
    }

    /**
     * The part of the query this member is allowed to search on.
     *
     * Masking happens when a message is rendered, but the index still holds
     * what was typed — so without this, searching for a blocked word returns
     * every message containing it. The asterisks hide the word; the hit itself
     * would say who used it and where, which is the thing the blocklist exists
     * to stop being casually browsable.
     *
     * Whoever runs the workspace keeps the whole query. They decide what is on
     * the list, and finding out whether it is being ignored is the reason to
     * have one. For everybody else the blocked words drop out of the term; a
     * query that was nothing but blocked words comes back empty, which reads as
     * "no results" rather than as an explanation of what is filtered.
     */
    private function searchable(string $terms, Workspace $workspace, User $user): string
    {
        if ($user->can('manage', $workspace)) {
            return $terms;
        }

        return $this->censorBlockedWords->strip($terms, $workspace->blocked_words);
    }
}
