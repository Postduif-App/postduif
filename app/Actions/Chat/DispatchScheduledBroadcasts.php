<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\ScheduledBroadcast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Send the announcements whose moment has come.
 *
 * Everything goes out through BroadcastToChannels, the same action an immediate
 * broadcast uses. That is the point of the whole design: the tag expansion and
 * the post-rights filter live in one place, so scheduling an announcement and
 * sending one now cannot drift apart without somebody noticing.
 */
class DispatchScheduledBroadcasts
{
    public function __construct(
        private readonly BroadcastToChannels $broadcastToChannels,
    ) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->claimDue() as $broadcast) {
            try {
                $this->send($broadcast);
                $sent++;
            } catch (Throwable $exception) {
                $this->giveUp($broadcast, $exception);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * The announcements due now, each claimed before anything is done with it.
     *
     * Stamped as sent before it is actually sent, exactly as
     * DispatchScheduledMessages does, and for the same trade: a crash in
     * between loses the announcement rather than posting it twice. An
     * announcement in six channels is the last thing that should arrive twice.
     *
     * @return Collection<int, ScheduledBroadcast>
     */
    private function claimDue(): Collection
    {
        return DB::transaction(function (): Collection {
            $due = ScheduledBroadcast::query()
                ->due()
                ->with(['channels', 'author'])
                ->lockForUpdate()
                ->get();

            ScheduledBroadcast::whereKey($due->pluck('id'))->update(['sent_at' => now()]);

            return $due;
        });
    }

    private function send(ScheduledBroadcast $broadcast): void
    {
        $author = $broadcast->author;

        if ($author === null) {
            throw new ScheduledMessageRefused(__('chat.broadcast_no_author'));
        }

        /*
         * Archived channels drop out here rather than making the whole
         * announcement fail: one closed channel out of six is not a reason for
         * the other five to go unsaid. Whether this member may still post is
         * BroadcastToChannels' question, asked now and not a week ago.
         */
        $channels = $broadcast->channels
            ->filter(fn (Channel $channel): bool => $channel->archived_at === null)
            ->values();

        $reached = $this->broadcastToChannels->handle($author, $channels, $broadcast->body);

        // Nowhere left to say it. Told rather than silently counted as sent:
        // somebody who scheduled an announcement wants to know it never landed.
        if ($reached === []) {
            throw new ScheduledMessageRefused(__('chat.broadcast_nowhere'));
        }
    }

    private function giveUp(ScheduledBroadcast $broadcast, Throwable $exception): void
    {
        $reason = $exception instanceof ScheduledMessageRefused
            ? $exception->getMessage()
            : __('chat.broadcast_failed');

        /*
         * Through the query builder rather than the model, which is not
         * fussiness: claimDue stamped sent_at with a mass update, so this
         * instance still believes it is null. A save() would compare against
         * that stale value, find nothing changed, and leave the row claimed
         * for good.
         */
        ScheduledBroadcast::whereKey($broadcast->id)->update([
            // Cleared again: it was stamped on the way in to claim it, and it
            // did not go out after all.
            'sent_at' => null,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
