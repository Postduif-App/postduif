<?php

namespace App\Models;

use App\Enums\Availability;
use Database\Factories\StatusRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of "on these days, between these hours, I am this".
 *
 * There is deliberately no separate "and outside those hours" setting. That is
 * just another rule — one covering every day and every hour — sitting below the
 * one that does not, and first match wins. One concept instead of two, and it
 * generalises: three rules stack the same way two do.
 *
 * @property int $id
 * @property int $user_id
 * @property int $position
 * @property array<int, int> $days ISO weekdays, 1 = Monday. Empty means daily.
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $status_emoji
 * @property string|null $status_text
 * @property Availability $availability
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['position', 'days', 'starts_at', 'ends_at', 'status_emoji', 'status_text', 'availability'])]
class StatusRule extends Model
{
    /** @use HasFactory<StatusRuleFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'days' => '[]',
        'position' => 0,
        'availability' => Availability::Available->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days' => 'array',
            'position' => 'integer',
            'availability' => Availability::class,
        ];
    }

    /**
     * Whether this rule is the one in force at a given local moment.
     *
     * The moment must already be in the member's own zone — see
     * User::localNow(). Everything here is a wall clock comparison and nothing
     * converts, which is the whole point: "nine o'clock" is a reading on a
     * clock, not an instant.
     *
     * That also settles the daylight saving question without any special case.
     * On the night the clocks go forward, a rule at 02:30 never matches,
     * because no clock in that zone ever reads 02:30 that night. On the night
     * they go back it matches twice, for the same reason. Both are what the
     * member's own clock did.
     */
    public function matchesAt(Carbon $localNow): bool
    {
        $time = $localNow->format('H:i:s');

        // Neither end set: the rule covers the whole of its days.
        if ($this->starts_at === null || $this->ends_at === null) {
            return $this->coversDay($localNow->dayOfWeekIso);
        }

        $start = $this->normalise($this->starts_at);
        $end = $this->normalise($this->ends_at);

        if ($start < $end) {
            return $this->coversDay($localNow->dayOfWeekIso)
                && $time >= $start
                && $time < $end;
        }

        /*
         * The window runs through midnight, so it belongs to the day it began
         * on: 22:00-06:00 on Monday means Monday evening into Tuesday morning.
         * Before midnight that is today's rule; after it, yesterday's.
         */
        return ($this->coversDay($localNow->dayOfWeekIso) && $time >= $start)
            || ($this->coversDay($localNow->copy()->subDay()->dayOfWeekIso) && $time < $end);
    }

    /** No days named at all means every day, which is how "always" is written. */
    private function coversDay(int $isoWeekday): bool
    {
        return $this->days === [] || in_array($isoWeekday, $this->days, true);
    }

    /**
     * Postgres hands a time column back as "09:00:00", a form may send "09:00".
     * Compared as text, so both have to be the same length.
     */
    private function normalise(string $time): string
    {
        return substr($time.':00', 0, 8);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
