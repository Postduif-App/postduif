<?php

namespace App\Actions\Huddles;

use App\Models\Channel;
use App\Models\ScheduledHuddle;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ScheduleHuddle
{
    /**
     * Put a huddle in the channel's diary.
     *
     * The invitees are narrowed to people who are actually in the channel, and
     * silently: the picker only offers members, so a stranger's id in the list
     * is somebody poking at the endpoint — and the useful answer to that is the
     * four colleagues who were legitimate, not an error naming the interesting
     * id.
     *
     * Nobody is notified here and nothing is posted. An appointment is not news
     * until it is nearly time, and a channel that announced every plan twice —
     * once when it was made and once when it started — would be a channel where
     * the second one goes unread.
     *
     * @param  array<int, int>  $inviteeIds
     *
     * @throws RuntimeException when the moment has already passed
     */
    public function handle(
        Channel $channel,
        User $organiser,
        string $title,
        CarbonInterface $startsAt,
        int $durationMinutes = 30,
        array $inviteeIds = [],
    ): ScheduledHuddle {
        if ($startsAt->isPast()) {
            throw new RuntimeException('A huddle cannot be scheduled for a moment that has passed.');
        }

        return DB::transaction(function () use ($channel, $organiser, $title, $startsAt, $durationMinutes, $inviteeIds): ScheduledHuddle {
            $scheduled = ScheduledHuddle::create([
                'channel_id' => $channel->id,
                'created_by' => $organiser->id,
                'title' => $title,
                /*
                 * To UTC before it is stored, for the same reason a reminder is:
                 * the moment arrives here on the organiser's own clock, and
                 * Eloquent's datetime cast writes whatever instance it is given
                 * rather than converting it — so "14:00 in Amsterdam" would be
                 * stored as the characters "14:00" and read back as two o'clock
                 * UTC.
                 */
                'starts_at' => $startsAt->utc(),
                'duration_minutes' => $durationMinutes,
            ]);

            $members = $channel->members()
                ->whereIn('users.id', $inviteeIds)
                ->pluck('users.id');

            $scheduled->invitees()->sync($members);

            return $scheduled;
        });
    }
}
