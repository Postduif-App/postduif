<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Chat\PresentMessage;
use App\Models\Channel;
use App\Models\Mention;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everywhere this member was named, in one list.
 *
 * The mentions table already feeds the badges in the sidebar; what it never had
 * was a place to read them. Four badges on a Monday morning means four channels
 * to walk through, and the one thing somebody wants to know — what is being
 * asked of me — is exactly what the badges cannot say.
 *
 * Inside the chat shell, like the ticket list: same sidebar, same unread
 * counts, same live connection. Nothing is marked read here — that happens by
 * opening the channel, which is where the answer gets written.
 */
class WorkspaceMentionController extends Controller
{
    /** Enough to catch up on; older than this and the channel is the place to look. */
    private const LIMIT = 50;

    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentMessage $presentMessage,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403, 'Je bent geen lid van deze workspace.');

        /*
         * Scoped to the channels the sidebar shows rather than to every mention
         * row. A member who was named in a channel and then removed from it
         * still has the row; showing it here would hand them a line out of a
         * conversation they can no longer open.
         */
        $channels = $this->buildChatShell->visibleChannels($workspace, $user)
            ->pluck('id');

        $mentions = Mention::query()
            ->where('user_id', $user->id)
            ->whereIn('channel_id', $channels)
            ->with(['message.author', 'message.channel', 'message.workspace'])
            // Unread first, and within each half the most recent: what still
            // wants an answer belongs above what has already had one.
            ->orderByRaw('read_at is not null')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            // A message that was deleted since leaves nothing to show.
            ->filter(fn (Mention $mention): bool => $mention->message !== null
                && ! $mention->message->isDeleted());

        return Inertia::render('chat/mentions', [
            ...$this->buildChatShell->handle($workspace, $user),
            'mentions' => $mentions
                ->map(function (Mention $mention) use ($user): array {
                    /*
                     * The thread shape carries the censoring and the author
                     * rules, so a word masked in the channel stays masked here.
                     * Spread first and then named over: its 'id' is the
                     * message's, and this row is addressed by the mention.
                     */
                    $summary = $this->presentMessage->threadSummary($mention->message);

                    return [
                        ...$summary,
                        'id' => $mention->id,
                        'messageId' => $summary['id'],
                        'readAt' => $mention->read_at?->toIso8601String(),
                        'channel' => [
                            'id' => $mention->message->channel_id,
                            'label' => $mention->message->channel->displayNameFor($user),
                            'type' => $mention->message->channel->type->value,
                        ],
                    ];
                })
                ->values()
                ->all(),
        ]);
    }
}
