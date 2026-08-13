<?php

namespace App\Actions\Huddles;

use App\Actions\Chat\SendMessage;
use App\Features\Huddles as HuddlesFeature;
use App\Models\ScheduledHuddle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnnounceScheduledHuddles
{
    /**
     * The name these post under, the same way ticket announcements have one.
     * One voice per subject, recognised faster than a name that shifts.
     */
    private const BOT_NAME = 'Huddles';

    public function __construct(private readonly SendMessage $sendMessage) {}

    /**
     * Say in the channel that a scheduled huddle has come round.
     *
     * A message rather than a notification, and that is the whole design: the
     * channel is where the button to join a huddle already lives, so the
     * announcement lands exactly where somebody has to act — and anybody who
     * wants a personal nudge can set a reminder on it, which is a feature this
     * application already has rather than a second one built into this.
     *
     * Each row is claimed before anything is posted. The schedule is not a
     * promise to run once, and a channel told twice that the same meeting has
     * started is worse than one told late.
     *
     * @return array{announced: int, skipped: int}
     */
    public function handle(): array
    {
        $announced = 0;
        $skipped = 0;

        ScheduledHuddle::query()
            ->due()
            ->with(['channel.workspace', 'invitees', 'organiser'])
            ->orderBy('starts_at')
            ->chunkById(200, function (Collection $scheduled) use (&$announced, &$skipped): void {
                foreach ($scheduled as $huddle) {
                    $this->announce($huddle) ? $announced++ : $skipped++;
                }
            });

        return ['announced' => $announced, 'skipped' => $skipped];
    }

    /**
     * One appointment. False means it was claimed by another run, or the
     * channel is no longer somewhere a huddle can happen.
     */
    private function announce(ScheduledHuddle $scheduled): bool
    {
        $claimed = DB::transaction(fn (): int => ScheduledHuddle::query()
            ->whereKey($scheduled->id)
            ->whereNull('announced_at')
            ->whereNull('cancelled_at')
            ->update(['announced_at' => now()]));

        if ($claimed === 0) {
            return false;
        }

        $channel = $scheduled->channel;

        /*
         * Asked now rather than when it was put in the diary. A workspace can
         * switch huddles off and a channel can be archived in the days between
         * — and the honest result is a diary entry that quietly lapses, not a
         * message inviting people into a room that no longer exists.
         */
        if ($channel === null || $channel->archived_at !== null) {
            return false;
        }

        if (! $channel->workspace->hasFeature(HuddlesFeature::class)) {
            return false;
        }

        $this->sendMessage->fromSystem($channel, $this->body($scheduled), self::BOT_NAME);

        return true;
    }

    /**
     * What the channel reads.
     *
     * The invitees are named by handle so the mention machinery reaches them —
     * being asked to a meeting is exactly the kind of thing the inbox is for,
     * and writing the handles is how this feature borrows that without owning
     * any of it. With nobody named, the line stands on its own: an appointment
     * with no invitee list is one for the channel at large.
     */
    private function body(ScheduledHuddle $scheduled): string
    {
        $line = sprintf(
            'Huddle nu: %s (%s–%s)',
            $scheduled->title,
            $scheduled->starts_at->format('H:i'),
            $scheduled->endsAt()->format('H:i'),
        );

        $handles = $scheduled->invitees
            ->map(fn (User $user): string => '@'.$user->username)
            ->implode(' ');

        return $handles === '' ? $line : $line."\n".$handles;
    }
}
