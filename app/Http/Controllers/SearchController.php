<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Full-text search over the workspace, scoped to the channels the member is
     * allowed to read. The workspace filter is not optional: it is the tenant
     * boundary, and a missing one here would leak another team's messages.
     */
    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();
        abort_unless($workspace->hasMember($user), 403);

        $terms = $request->string('q')->trim()->value();

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
                'body' => $message->body,
                'createdAt' => $message->created_at?->toIso8601String(),
                'author' => $message->author->name,
                'channel' => [
                    'id' => $message->channel->id,
                    'name' => $message->channel->name,
                    'type' => $message->channel->type->value,
                ],
            ]);

        return response()->json(['results' => $results]);
    }
}
