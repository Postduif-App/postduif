<?php

namespace App\Actions\Chat;

use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonInterface;
use RuntimeException;

class ScheduleReminder
{
    /**
     * Put a message aside to be brought back at a given moment.
     *
     * The moment is taken as it is rather than rounded or nudged: somebody who
     * picks 09:00 means 09:00, the same reasoning the scheduled-message
     * dispatcher runs on.
     *
     * Setting one again on a message you already have a reminder for moves it
     * rather than making a second — an updateOrCreate over the partial unique
     * index, which is what makes "herinner me hier over een uur aan" a harmless
     * repeat click instead of two that both go off. The note goes with it: the
     * later click is the more recent intention.
     *
     * @throws RuntimeException when the moment has already passed
     */
    public function handle(User $user, Message $message, CarbonInterface $remindAt, ?string $note = null): Reminder
    {
        /*
         * A reminder in the past would be delivered by the next sweep, roughly
         * a minute later, which is not what anybody meant by it — and refusing
         * is the only answer that says so. Not a validation rule on the
         * controller alone: the moment is worked out from a member's own
         * timezone, and the arithmetic that gets that wrong is exactly the
         * caller this has to catch.
         */
        if ($remindAt->isPast()) {
            throw new RuntimeException('A reminder cannot be set for a moment that has passed.');
        }

        /*
         * Brought back to UTC before it is stored, and this is not decoration.
         * The moment arrives here in the member's own timezone — it has to, or
         * "morgenochtend" cannot mean their morning — and Eloquent's datetime
         * cast formats whatever instance it is given rather than converting it.
         * A Carbon reading 09:00 in Amsterdam would therefore be written as the
         * characters "09:00" and read back as nine o'clock UTC: an hour late in
         * winter and two in summer.
         */
        $remindAt = $remindAt->utc();

        return Reminder::updateOrCreate(
            [
                'user_id' => $user->id,
                'message_id' => $message->id,
                'delivered_at' => null,
            ],
            [
                // Off the message rather than passed in, so the row cannot
                // disagree with the message it points at.
                'channel_id' => $message->channel_id,
                'remind_at' => $remindAt,
                'note' => $note,
            ],
        );
    }
}
