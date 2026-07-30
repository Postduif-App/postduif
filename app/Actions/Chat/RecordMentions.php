<?php

namespace App\Actions\Chat;

use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class RecordMentions
{
    /**
     * A handle is the part after "@": lowercase letters, digits, dots, dashes
     * and underscores. Trailing dots are excluded so "hoi @fenna." mentions
     * Fenna rather than looking for a handle that ends in a full stop.
     */
    private const PATTERN = '/@([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)/i';

    /**
     * Store a row per mentioned member and return the users that were tagged.
     *
     * Only members of the channel are recorded. Mentioning someone who cannot
     * read the channel would either notify them about a conversation they have
     * no access to, or leave a dangling row nobody ever clears.
     *
     * @return Collection<int, User>
     */
    public function handle(Message $message): Collection
    {
        $handles = $this->handlesIn($message->body);

        if ($handles->isEmpty()) {
            return new Collection;
        }

        $mentioned = $message->channel->members()
            ->whereIn('username', $handles)
            ->whereKeyNot($message->user_id)
            ->get();

        foreach ($mentioned as $user) {
            Mention::firstOrCreate([
                'message_id' => $message->id,
                'user_id' => $user->id,
            ], [
                'channel_id' => $message->channel_id,
            ]);
        }

        return $mentioned;
    }

    /**
     * @return Collection<int, string>
     */
    private function handlesIn(string $body): Collection
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return (new Collection($matches[1]))
            ->map(fn (string $handle) => mb_strtolower($handle))
            ->unique()
            ->values();
    }
}
