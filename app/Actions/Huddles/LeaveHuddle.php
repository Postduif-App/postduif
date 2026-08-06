<?php

namespace App\Actions\Huddles;

use App\Events\HuddleUpdated;
use App\Models\Huddle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Take somebody out of a huddle, and close it behind the last one.
 *
 * Closing is not a separate button anywhere. A huddle is over when the people
 * in it have gone, and a "beëindigen" that one person could press for everybody
 * would be a way to hang up on a colleague.
 */
class LeaveHuddle
{
    public function handle(Huddle $huddle, User $user): void
    {
        DB::transaction(function () use ($huddle, $user): void {
            $huddle->present()->where('user_id', $user->id)->update(['left_at' => now()]);

            if ($huddle->present()->count() === 0 && $huddle->isLive()) {
                $huddle->forceFill(['ended_at' => now()])->save();
            }

            HuddleUpdated::dispatch($huddle);
        });
    }
}
