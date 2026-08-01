<?php

namespace App\Actions\Chat;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StartDirectMessage
{
    public function __construct(
        private readonly HideDirectMessage $hideDirectMessage,
    ) {}

    /**
     * Open the conversation between two people, creating it if this is the
     * first time.
     *
     * Deliberately not CreateChannel: that one slugs a name and puts only its
     * creator inside. A DM has no name, and one participant makes no sense.
     */
    public function handle(Workspace $workspace, User $initiator, User $recipient): Channel
    {
        if ($initiator->is($recipient)) {
            throw new InvalidArgumentException('Een DM met jezelf bestaat niet.');
        }

        return DB::transaction(function () use ($workspace, $initiator, $recipient) {
            $existing = $this->findBetween($workspace, $initiator, $recipient);

            if ($existing !== null) {
                // Picking this person out of the list is asking for the
                // conversation back, whatever was done with it before.
                $this->hideDirectMessage->reopen($existing, $initiator);

                return $existing;
            }

            $channel = Channel::create([
                'workspace_id' => $workspace->id,
                'type' => ChannelType::Direct,
                'name' => null,
                'slug' => null,
                'topic' => null,
                'created_by' => $initiator->id,
            ]);

            $channel->members()->attach([
                $initiator->id => ['joined_at' => now()],
                $recipient->id => ['joined_at' => now()],
            ]);

            return $channel;
        });
    }

    /**
     * The existing conversation between exactly these two, if there is one.
     *
     * The database cannot answer this on its own: the unique index is
     * (workspace_id, slug) and a DM's slug is null, which Postgres never treats
     * as a duplicate. So a DM's identity lives entirely in who is in it, and
     * this query is the only thing standing between the member and a second
     * conversation with the same person.
     *
     * The member count is part of the question, not a detail. Without it a
     * future group DM that happens to contain both people would answer to
     * "the DM with Jan".
     */
    private function findBetween(Workspace $workspace, User $initiator, User $recipient): ?Channel
    {
        return $workspace->channels()
            ->where('type', ChannelType::Direct)
            ->whereHas('members', fn (Builder $members) => $members->whereKey($initiator->id))
            ->whereHas('members', fn (Builder $members) => $members->whereKey($recipient->id))
            ->has('members', '=', 2)
            ->first();
    }
}
