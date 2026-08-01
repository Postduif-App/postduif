<?php

namespace App\Actions\Users;

use App\Enums\Availability;
use App\Events\StatusChanged;
use App\Models\StatusRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SetStatus
{
    /**
     * How many earlier statuses the picker offers back.
     *
     * Short on purpose: the list exists so "In vergadering" is one click away,
     * not so somebody can browse their year. Past five it stops being a
     * shortcut and starts being a history nobody asked to keep.
     */
    public const RECENT_LIMIT = 5;

    /**
     * Set somebody's status, and remember it for next time.
     *
     * Emoji and text move together — a status is one thing said two ways, and
     * clearing it clears both. Availability is set in the same call because
     * that is how it is chosen in the interface, but it is independent: being
     * away is not a status, and having one does not make you unavailable.
     */
    public function handle(
        User $user,
        ?string $emoji,
        ?string $text,
        Availability $availability,
        ?StatusRule $fromRule = null,
    ): User {
        $emoji = $this->blankToNull($emoji);
        $text = $this->blankToNull($text);

        $user->forceFill([
            'status_emoji' => $emoji,
            'status_text' => $text,
            'availability' => $availability,
            /*
             * A status a schedule put there is not a shortcut worth offering
             * back: the whole point of the rule is that nobody has to pick it.
             * Only what somebody typed goes in the recent list.
             */
            'recent_statuses' => $fromRule === null
                ? $this->remember($user, $emoji, $text)
                : $user->recent_statuses,
            /*
             * Which window this status belongs to, and whether it was typed.
             *
             * A manual status is remembered as belonging to whatever rule is in
             * force right now — that is what lets it win until that window ends
             * and no longer. Set your own status at ten, and the evening rule
             * still takes over at five.
             */
            'status_rule_id' => $fromRule === null
                ? $user->activeStatusRule()?->id
                : $fromRule->id,
            'status_is_manual' => $fromRule === null,
        ])->save();

        $this->announce($user, $emoji, $text, $availability);

        return $user;
    }

    /**
     * Tell everyone who can see this status that it changed.
     *
     * That is whoever shares a channel with them — which is exactly the set of
     * people the app ever draws it for: the member list of a channel, and the
     * row of a one-on-one in the sidebar. Themselves included, because their
     * own menu shows it back to them.
     *
     * One query for the recipients rather than a walk over the channels: a
     * member of twenty channels would otherwise mean twenty round trips for
     * something as small as "koffie".
     */
    private function announce(
        User $user,
        ?string $emoji,
        ?string $text,
        Availability $availability,
    ): void {
        $channelIds = $user->channels()->pluck('channels.id');

        User::query()
            ->whereKey($user->id)
            ->orWhereHas('channels', fn (Builder $channels) => $channels->whereIn('channels.id', $channelIds))
            ->pluck('id')
            ->each(fn (int $recipientId) => StatusChanged::dispatch(
                $recipientId,
                $user->id,
                $emoji,
                $text,
                $availability,
            ));
    }

    /**
     * The recent list with this status moved to the front.
     *
     * Clearing a status does not touch the list: you would be dropping the very
     * shortcuts the list exists for, at the moment you are least likely to mean
     * it. An identical earlier entry is removed rather than left in place, so
     * reusing a status promotes it instead of duplicating it.
     *
     * @return array<int, array{emoji: string|null, text: string}>
     */
    private function remember(User $user, ?string $emoji, ?string $text): array
    {
        if ($text === null) {
            return $user->recent_statuses;
        }

        // Compared field by field rather than as whole arrays: these rows come
        // back out of a jsonb column, which does not keep the key order they
        // went in with, so === would call every entry different from itself.
        return collect($user->recent_statuses)
            ->reject(fn (array $recent): bool => $recent['text'] === $text
                && $recent['emoji'] === $emoji)
            ->prepend(['emoji' => $emoji, 'text' => $text])
            ->take(self::RECENT_LIMIT)
            ->values()
            ->all();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
