<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelPresence
{
    /**
     * The ids of the members who have this channel open right now.
     *
     * Reverb already knows this: everyone reading a channel is subscribed to
     * its presence channel. It speaks the Pusher HTTP API, so we can simply
     * ask, rather than keeping a second copy of the same fact in our own
     * database that would drift the moment a browser closes without saying so.
     *
     * @return Collection<int, int>
     */
    public function handle(Channel $channel): Collection
    {
        $broadcaster = Broadcast::driver();

        if (! $broadcaster instanceof PusherBroadcaster) {
            return new Collection;
        }

        try {
            $response = $broadcaster->getPusher()->get(
                '/channels/presence-chat.channel.'.$channel->id.'/users'
            );
        } catch (Throwable $exception) {
            // An unreachable websocket server must not take a message down with
            // it. Nobody is "here" as far as we can tell, so @here reaches
            // nobody — quieter than the alternative of notifying everyone.
            Log::warning('Could not read channel presence from the websocket server.', [
                'channel_id' => $channel->id,
                'exception' => $exception->getMessage(),
            ]);

            return new Collection;
        }

        // The Pusher SDK answers with stdClass, not arrays, and has changed its
        // mind about that across versions. data_get() reads either shape, so a
        // library upgrade cannot quietly turn this into an empty list.
        /*
         * Filtered before the cast rather than after it. Same outcome, but
         * dropping falsy values from a list of ints narrows the type to
         * "non-zero int" — and a Collection is invariant, so that no longer
         * fits the Collection<int, int> this promises.
         */
        return (new Collection(data_get($response, 'users', [])))
            ->filter(fn ($user): bool => (int) data_get($user, 'id') !== 0)
            ->map(fn ($user): int => (int) data_get($user, 'id'))
            ->values();
    }
}
