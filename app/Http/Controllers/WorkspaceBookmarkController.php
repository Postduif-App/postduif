<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Chat\PresentMessage;
use App\Models\Bookmark;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything this member set aside, across channels.
 *
 * The same shape as the mention list next to it: one shell, one sidebar, rows
 * that lead back to the message they came from. What differs is the ordering —
 * most recently saved first, because saving is an act of "later" and the last
 * thing you meant to come back to is usually the nearest.
 */
class WorkspaceBookmarkController extends Controller
{
    private const LIMIT = 100;

    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentMessage $presentMessage,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, 'Je bent geen lid van deze workspace.');

        /*
         * Scoped to the channels the sidebar shows. Somebody who saved a
         * message and was then taken out of the channel keeps the row, but not
         * the line out of it — the same rule the mention list follows.
         */
        $channels = $this->buildChatShell->visibleChannels($workspace, $user)->pluck('id');

        $bookmarks = Bookmark::query()
            ->where('user_id', $user->id)
            ->whereIn('channel_id', $channels)
            ->with(['message.author', 'message.channel', 'message.workspace'])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (Bookmark $bookmark): bool => $bookmark->message !== null
                && ! $bookmark->message->isDeleted());

        return Inertia::render('chat/saved', [
            ...$this->buildChatShell->handle($workspace, $user),
            'saved' => $bookmarks
                ->map(function (Bookmark $bookmark) use ($user): array {
                    $summary = $this->presentMessage->threadSummary($bookmark->message);

                    return [
                        ...$summary,
                        'id' => $bookmark->id,
                        'messageId' => $summary['id'],
                        'savedAt' => $bookmark->created_at?->toIso8601String(),
                        'channel' => [
                            'id' => $bookmark->message->channel_id,
                            'label' => $bookmark->message->channel->displayNameFor($user),
                            'type' => $bookmark->message->channel->type->value,
                        ],
                    ];
                })
                ->values()
                ->all(),
        ]);
    }
}
