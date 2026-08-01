<?php

namespace App\Console\Commands;

use App\Actions\Chat\FindMissedActivity;
use App\Enums\Availability;
use App\Models\User;
use App\Notifications\ChannelActivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type MissedChannel from FindMissedActivity
 */
#[Signature('chat:notify-absent')]
#[Description('Tell members what happened in the channels they have been away from')]
class NotifyAbsentMembers extends Command
{
    /**
     * One summary per member per workspace, for whatever they missed.
     *
     * Chunked over the members who asked for this rather than over every
     * membership: the question is per person, and the interesting number is how
     * many people have this switched on at all.
     */
    public function handle(FindMissedActivity $findMissedActivity): int
    {
        $notified = 0;

        User::query()
            ->whereNotNull('notify_after_minutes')
            ->whereNull('suspended_at')
            // FindMissedActivity refuses these anyway; leaving them out of the
            // query means not doing the work to find that out per member.
            ->whereNot('availability', Availability::DoNotDisturb->value)
            ->where(fn ($query) => $query
                ->where('notify_via_mail', true)
                ->orWhere('notify_via_pushover', true))
            ->chunkById(100, function (Collection $users) use ($findMissedActivity, &$notified) {
                foreach ($users as $user) {
                    $notified += $this->notify($user, $findMissedActivity);
                }
            });

        $this->info($notified === 1
            ? '1 samenvatting verstuurd.'
            : $notified.' samenvattingen verstuurd.');

        return self::SUCCESS;
    }

    /**
     * @return int the number of summaries sent to this member
     */
    private function notify(User $user, FindMissedActivity $findMissedActivity): int
    {
        $missed = $findMissedActivity->handle($user);

        foreach ($missed as $workspace) {
            $user->notify(new ChannelActivity($workspace['workspace'], $workspace['channels']));

            // Moved after the notification is handed over, not before: a queue
            // that never runs is a summary that never arrived, and the pointer
            // would have written it off as told. The reverse — telling somebody
            // twice — is the failure worth risking of the two.
            $this->markReported($user, $workspace['channels']);
        }

        return $missed->count();
    }

    /**
     * Advance the notified pointer to the newest message reported in each
     * channel, so the next run has nothing to say until somebody posts again.
     *
     * @param  Collection<int, MissedChannel>  $channels  The whole shape, not
     *                                                    just the two fields used here: a Collection is invariant in
     *                                                    its value type, so a narrower promise would not accept it.
     */
    private function markReported(User $user, Collection $channels): void
    {
        foreach ($channels as $channel) {
            DB::table('channel_user')
                ->where('user_id', $user->id)
                ->where('channel_id', $channel['channelId'])
                ->update([
                    'last_notified_message_id' => $channel['newestId'],
                    'updated_at' => now(),
                ]);
        }
    }
}
