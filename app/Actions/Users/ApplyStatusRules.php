<?php

namespace App\Actions\Users;

use App\Enums\Availability;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Put everybody's scheduled status in force, and take it away again when the
 * window it belonged to has passed.
 *
 * The interesting question is not which rule applies — StatusRule answers that
 * — but what to do about somebody who set their own status in the meantime.
 * The answer here: their own wins until the window they set it in ends. You
 * type "even bellen" at ten and the evening rule still takes over at five, so
 * nobody has to remember to undo themselves. That is the thing scheduling was
 * supposed to save them from.
 */
class ApplyStatusRules
{
    /** Enough per round trip to be cheap, small enough not to hold the table. */
    private const CHUNK = 200;

    public function __construct(private readonly SetStatus $setStatus) {}

    /**
     * @return array{applied: int, cleared: int}
     */
    public function handle(): array
    {
        $applied = 0;
        $cleared = 0;

        User::query()
            ->whereHas('statusRules')
            ->with('statusRules')
            ->chunkById(self::CHUNK, function (Collection $users) use (&$applied, &$cleared): void {
                foreach ($users as $user) {
                    match ($this->settle($user)) {
                        'applied' => $applied++,
                        'cleared' => $cleared++,
                        default => null,
                    };
                }
            });

        return ['applied' => $applied, 'cleared' => $cleared];
    }

    /**
     * Bring one member's status in line with their rules.
     *
     * @return 'applied'|'cleared'|'unchanged'
     */
    private function settle(User $user): string
    {
        $rule = $user->activeStatusRule();

        /*
         * They said something themselves inside this same window. Leave it —
         * and note that this is the only branch that looks at status_is_manual,
         * because the moment the window changes the comparison below fails and
         * the schedule is back in charge without anything having to expire.
         */
        if ($user->status_is_manual && $user->status_rule_id === $rule?->id) {
            return 'unchanged';
        }

        if ($rule === null) {
            return $this->clear($user);
        }

        // Already showing this rule's status, and nobody has touched it.
        if ($user->status_rule_id === $rule->id && ! $user->status_is_manual) {
            return 'unchanged';
        }

        $this->setStatus->handle(
            $user,
            $rule->status_emoji,
            $rule->status_text,
            $rule->availability,
            $rule,
        );

        return 'applied';
    }

    /**
     * No rule covers this moment.
     *
     * Only a status a rule put there is taken away. Somebody who typed one
     * outside every window meant it, and a scheduler that tidied it up would be
     * deleting what it was never asked to manage.
     *
     * @return 'cleared'|'unchanged'
     */
    private function clear(User $user): string
    {
        if ($user->status_rule_id === null && $user->status_is_manual) {
            return 'unchanged';
        }

        /*
         * Through SetStatus so the people who can see it are told, then the two
         * markers corrected: handle() records a status as typed, and this was
         * the schedule letting go rather than the member saying anything.
         */
        $this->setStatus->handle($user, null, null, Availability::Available, null);

        $user->forceFill(['status_is_manual' => false, 'status_rule_id' => null])->save();

        return 'cleared';
    }
}
