<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Say the same thing in several channels at once.
 *
 * Each channel gets a message of its own through the ordinary SendMessage
 * rather than one message pointing at many channels. A shared message would
 * have to answer what a reply in one channel means for the others, and what
 * happens when it is edited or deleted in one place — questions nobody asking
 * for this has in mind. What they want is to type it once.
 */
class BroadcastToChannels
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    /**
     * @param  Collection<int, Channel>  $channels
     * @return array<int, Channel> The channels it actually went to.
     */
    public function handle(User $author, Collection $channels, string $body): array
    {
        return $channels
            // Checked here rather than trusted from the request: a tag expands
            // to whatever carries it, which may include a channel this member
            // reads along in but may not post to.
            ->filter(fn (Channel $channel): bool => $author->can('post', $channel))
            ->each(fn (Channel $channel) => $this->sendMessage->handle($channel, $author, $body))
            ->values()
            ->all();
    }
}
