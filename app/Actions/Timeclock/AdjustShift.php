<?php

namespace App\Actions\Timeclock;

use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

/**
 * Putting a recorded stretch right.
 *
 * The reason this feature exists at all: people forget to clock in, forget to
 * clock out, and remember at four in the afternoon. Without a way to say what
 * actually happened, the honest thing to do with a wrong total is ignore it —
 * and a record everybody ignores is worse than none.
 *
 * Every change is stamped as a correction. Not to catch anybody out, but
 * because "8 uur" that a clock recorded and "8 uur" that somebody typed in
 * afterwards are two different claims, and the screen should be able to tell
 * them apart.
 *
 * What a corrected stretch still has to be true of is GuardShift's question —
 * the same one RecordShift asks of a stretch being added from scratch.
 */
class AdjustShift
{
    public function __construct(private readonly GuardShift $guardShift) {}

    /**
     * @param  Carbon|null  $endedAt  Null leaves a running shift running. It
     *                                cannot end one that has already finished — see GuardShift.
     */
    public function handle(TimeEntry $entry, Carbon $startedAt, ?Carbon $endedAt): TimeEntry
    {
        $this->guardShift->handle($entry, $startedAt, $endedAt);

        $entry->forceFill([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'corrected_at' => Carbon::now(),
        ])->save();

        return $entry;
    }
}
