<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Full-text search over one workspace, scoped to what a member may read.
 *
 * Pulled out of SearchController once a second caller appeared: the MCP tool
 * asks exactly the same question, and a second query that ought to agree with
 * this one would drift — most dangerously around the blocklist, where drifting
 * means a blocked word is one AI client away from readable.
 *
 * Three rules travel together here, and all three matter:
 *
 * - The workspace filter is the tenant boundary, not a convenience.
 * - The channel scope is what a member may read, which is not the same as what
 *   exists.
 * - The blocked words come out of the search term for anybody who does not run
 *   the workspace, because the index still holds what was typed.
 *
 * @phpstan-type SearchHit array{id: string, body: string, createdAt: string|null, author: string|null, authorIsBot: bool, threadId: string|null, channel: array{id: int, name: string|null, type: string}}
 */
class SearchMessages
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * @param  Channel|null  $channel  Narrow to one channel. Ignored when the
     *                                 member cannot read it — a channel they
     *                                 may not see finds nothing rather than
     *                                 saying it exists.
     * @param  User|null  $from  Narrow to one author. A handle that resolves to
     *                           nobody is refused by the caller rather than
     *                           silently ignored here, because "from:fena"
     *                           finding everything Fenna's colleagues wrote is
     *                           worse than finding nothing.
     *                           Returns a list rather than a Collection: a Collection is invariant in its
     *                           value type, so any hit shaped even slightly more precisely than the
     *                           declaration — a non-empty string where a string was promised — no longer
     *                           fits. An array is covariant and simply does.
     * @return array<int, SearchHit>
     */
    public function handle(
        Workspace $workspace,
        User $user,
        string $terms,
        ?Channel $channel = null,
        ?User $from = null,
        int $limit = 30,
    ): array {
        $terms = $this->searchable(trim($terms), $workspace, $user);

        if ($terms === '') {
            return [];
        }

        $visibleChannelIds = $workspace->channels()
            ->visibleTo($user)
            ->pluck('id');

        if ($channel !== null) {
            $visibleChannelIds = $visibleChannelIds->intersect([$channel->id]);
        }

        return Message::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('channel_id', $visibleChannelIds)
            /*
             * Only messages a person wrote. Webhook posts are deliberately out:
             * they carry a bot_name and no user_id, so "from:" would have to
             * mean two different things — a member and a label a webhook chose
             * for itself — and the second is not something anybody can be held
             * to. A bot that wants finding has its name in the text.
             */
            ->when($from, fn (Builder $query) => $query->where('user_id', $from->id))
            ->matching($terms)
            ->with(['author:id,name', 'channel:id,name,type'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                // Built here rather than through PresentMessage, so the
                // blocklist has to be applied here too — otherwise a blocked
                // word is one search away from readable.
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
                    'type' => $this->channelType($message->channel),
                ],
            ])
            ->all();
    }

    /**
     * A channel's type as a plain string.
     *
     * A method rather than ->value inline: the enum narrows that to the exact
     * set of cases, and a Collection is invariant in its value type — so the
     * narrower shape no longer fits what this promises. Naming the union in
     * the docblock instead would break the day a case is added.
     */
    private function channelType(Channel $channel): string
    {
        return $channel->type->value;
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
     * have one.
     */
    private function searchable(string $terms, Workspace $workspace, User $user): string
    {
        if ($user->can('manage', $workspace)) {
            return $terms;
        }

        return $this->censorBlockedWords->strip($terms, $workspace->blocked_words);
    }
}
