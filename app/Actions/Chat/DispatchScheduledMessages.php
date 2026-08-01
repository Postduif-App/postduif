<?php

namespace App\Actions\Chat;

use App\Models\ScheduledMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Say the things whose moment has come.
 *
 * Everything goes out through the ordinary SendMessage, which is the point: a
 * scheduled message is a message somebody wrote earlier, and a second sending
 * path would be a second place for mentions, unread counts and broadcasts to be
 * forgotten.
 */
class DispatchScheduledMessages
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    /**
     * @return array{sent: int, failed: int}
     */
    public function handle(): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->claimDue() as $scheduled) {
            try {
                $this->send($scheduled);
                $sent++;
            } catch (Throwable $exception) {
                $this->giveUp($scheduled, $exception);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * The messages due now, each claimed before anything is done with it.
     *
     * Claiming is what stops one being said twice. The row is stamped as sent
     * before the message is actually posted, and only rows this call managed to
     * stamp are worked on — so a second run that starts while this one is
     * halfway sees nothing left to do, with or without withoutOverlapping on
     * the schedule.
     *
     * The cost is the other way round: a crash between the stamp and the post
     * loses the message rather than repeating it. That is the safer of the two
     * — an announcement posted twice cannot be taken back, and a member who
     * sees theirs never arrived can send it again.
     *
     * @return Collection<int, ScheduledMessage>
     */
    private function claimDue()
    {
        return DB::transaction(function () {
            $due = ScheduledMessage::query()
                ->due()
                ->with(['channel', 'author'])
                ->lockForUpdate()
                ->get();

            ScheduledMessage::whereKey($due->pluck('id'))->update(['sent_at' => now()]);

            return $due;
        });
    }

    private function send(ScheduledMessage $scheduled): void
    {
        $channel = $scheduled->channel;
        $author = $scheduled->author;

        // Checked again at the moment of sending, not only when it was
        // scheduled: a week is long enough to be removed from a channel, or for
        // the channel to be closed to everyone but its admins.
        if ($channel === null || $author === null || $author->cannot('post', $channel)) {
            throw new ScheduledMessageRefused(
                'Je mocht op dat moment niet meer posten in dit kanaal.'
            );
        }

        $this->sendMessage->handle($channel, $author, $scheduled->body);
    }

    /**
     * Written straight to the row rather than through the model.
     *
     * The instance was read before the claim stamped sent_at, so it still
     * believes that column is null — and saving null over null is not a change
     * Eloquent would send. The stamp would survive, leaving a message marked
     * both sent and failed.
     */
    private function giveUp(ScheduledMessage $scheduled, Throwable $exception): void
    {
        $reason = $exception instanceof ScheduledMessageRefused
            ? $exception->getMessage()
            : 'Er ging iets mis bij het versturen.';

        ScheduledMessage::whereKey($scheduled->id)->update([
            // Cleared again: it was stamped on the way in to claim it, and it
            // did not go out after all.
            'sent_at' => null,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
