<?php

namespace App\Actions\Chat;

use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class RecordMentions
{
    /**
     * A handle is the part after "@": letters, digits, dots, dashes and
     * underscores. Trailing dots are excluded so "hoi @fenna." mentions Fenna
     * rather than looking for a handle that ends in a full stop.
     *
     * The "@" must start a word, so "mail naar hallo@fenna.nl" is an address
     * rather than a mention. MessageBody applies the same rule when it decides
     * what to highlight; the two must stay in step, or the interface will
     * promise a notification that never gets sent.
     */
    private const PATTERN = '/(?:^|\s)@([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)/i';

    /**
     * Handles that address a group rather than a person. Nobody may register
     * them as a username, so there is no ambiguity to resolve.
     */
    private const EVERYONE = 'everyone';

    private const HERE = 'here';

    public function __construct(private readonly ChannelPresence $presence) {}

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

        $mentioned = $this->byHandle($message, $handles)
            ->merge($this->broadcast($message, $handles))
            ->unique('id')
            ->values();

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
     * @param  Collection<int, string>  $handles
     * @return Collection<int, User>
     */
    private function byHandle(Message $message, Collection $handles): Collection
    {
        return $message->channel->members()
            ->whereIn('username', $handles)
            ->unless($message->isFromBot(), fn ($members) => $members->whereKeyNot($message->user_id))
            ->get();
    }

    /**
     * Members reached by @everyone or @here.
     *
     * Silently empty when the author is not allowed to use them: the message
     * still sends and still reads sensibly, it simply notifies nobody. Refusing
     * the whole message over one word would lose what somebody just typed.
     *
     * @param  Collection<int, string>  $handles
     * @return Collection<int, User>
     */
    private function broadcast(Message $message, Collection $handles): Collection
    {
        $wantsEveryone = $handles->contains(self::EVERYONE);
        $wantsHere = $handles->contains(self::HERE);

        if (! $wantsEveryone && ! $wantsHere) {
            return new Collection;
        }

        // A webhook never gets to summon the whole channel. It has no member
        // behind it to hold responsible, and BroadcastMentionPolicy has no
        // answer for something that is not a member of the workspace.
        if ($message->isFromBot()) {
            return new Collection;
        }

        if ($message->author->cannot('broadcastMention', $message->channel->workspace)) {
            return new Collection;
        }

        $members = $message->channel->members()
            ->whereKeyNot($message->user_id)
            ->get();

        if ($wantsEveryone) {
            return $members;
        }

        $present = $this->presence->handle($message->channel);

        return $members->filter(fn (User $member) => $present->contains($member->id))->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function handlesIn(string $body): Collection
    {
        preg_match_all(self::PATTERN, $body, $matches);

        $handles = array_map($this->normalise(...), $matches[1]);

        return (new Collection($handles))->unique()->values();
    }

    /**
     * One handle, as it is stored: lowercase.
     *
     * A method rather than mb_strtolower() inline, because its declared return
     * type is what makes the list a list of strings. Called directly, the
     * lowercasing narrows the type to "lowercase-string" — and a Collection is
     * invariant in its value type, so that no longer fits what this promises.
     */
    private function normalise(string $handle): string
    {
        return mb_strtolower($handle);
    }
}
