<?php

namespace App\Actions\Timeclock;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adding up what the clock recorded, for one week at a time.
 *
 * A week rather than a month or a running total, because a week is the unit
 * somebody checks against: "heb ik genoeg gedraaid" is a question about the one
 * you are in. The screen can walk back through earlier ones, and each of those
 * is another call to this.
 *
 * Everything here is worked out in the member's own zone. A shift is stored as
 * a moment, but "welke dag" and "welke week" are readings on a clock, and the
 * only clock that matters is the one on the wall the member was sitting in
 * front of.
 */
class SummariseHours
{
    /**
     * A member's own week: the totals, the days, and every stretch in it.
     *
     * @return array{
     *     from: string,
     *     until: string,
     *     seconds: int,
     *     days: list<array{date: string, seconds: int}>,
     *     entries: list<array<string, mixed>>,
     * }
     */
    public function forMember(User $member, Workspace $workspace, ?Carbon $anchor = null): array
    {
        [$from, $until] = $this->week($member, $anchor);

        $entries = $workspace->timeEntries()
            ->where('user_id', $member->id)
            ->overlapping($from, $until)
            ->get();

        /*
         * Kept, but not counted twice.
         *
         * A shift that began on Sunday evening and ran past midnight overlaps
         * this week and shows up in the query, and it belongs to the week it
         * started in — the same rule that decides which *day* it counts
         * towards. Filtered here rather than in SQL because the answer depends
         * on the member's zone, which the database has no opinion about.
         */
        $entries = $entries->filter(
            fn (TimeEntry $entry): bool => $entry->localDate($member) >= $from->toDateString()
                && $entry->localDate($member) < $until->toDateString()
        )->values();

        $byDay = $entries->groupBy(fn (TimeEntry $entry): string => $entry->localDate($member));

        $days = [];

        for ($day = $from->copy(); $day < $until; $day->addDay()) {
            $date = $day->toDateString();

            $days[] = [
                'date' => $date,
                'seconds' => $this->total($byDay->get($date) ?? collect()),
            ];
        }

        return [
            'from' => $from->toDateString(),
            // Inclusive where a person reads it: the query wants the moment the
            // week is over, the screen wants Sunday.
            'until' => $until->copy()->subDay()->toDateString(),
            'seconds' => $this->total($entries),
            'days' => $days,
            'entries' => $entries
                ->sortByDesc('started_at')
                ->map(fn (TimeEntry $entry): array => $this->present($entry, $member))
                ->values()
                ->all(),
        ];
    }

    /**
     * Half a year of days, a column per week, for the chart above the week.
     *
     * The shape is deliberately the one GitHub made everybody able to read at a
     * glance: weeks left to right, Monday at the top, and a square that gets
     * darker the more was worked. What it answers is the question the week view
     * cannot — "hoe zit mijn afgelopen half jaar eruit" — and it answers it in
     * the time it takes to look at it.
     *
     * @return array{weeks: list<list<array{date: string, seconds: int, level: int, future: bool}>>, from: string, until: string}
     */
    public function calendar(User $member, Workspace $workspace, int $weeks): array
    {
        [$thisWeek] = $this->week($member);

        $from = $thisWeek->copy()->subWeeks(max(0, $weeks - 1));
        $until = $thisWeek->copy()->addWeek();

        $entries = $workspace->timeEntries()
            ->where('user_id', $member->id)
            ->overlapping($from, $until)
            ->get();

        $byDay = $entries->groupBy(fn (TimeEntry $entry): string => $entry->localDate($member));

        $today = $member->localNow()->toDateString();

        $columns = [];

        for ($day = $from->copy(); $day < $until; $day->addWeek()) {
            $column = [];

            for ($offset = 0; $offset < 7; $offset++) {
                $date = $day->copy()->addDays($offset)->toDateString();

                /*
                 * Beyond the day it belongs to a week that has not happened
                 * yet. Sent rather than left out, so every column is seven
                 * squares tall and the grid does not go ragged at the end —
                 * the screen draws these as nothing at all.
                 */
                if ($date > $today) {
                    $column[] = ['date' => $date, 'seconds' => 0, 'level' => 0, 'future' => true];

                    continue;
                }

                $seconds = $this->total($byDay->get($date) ?? collect());

                $column[] = [
                    'date' => $date,
                    'seconds' => $seconds,
                    'level' => $this->level($seconds),
                    'future' => false,
                ];
            }

            $columns[] = $column;
        }

        return [
            'weeks' => $columns,
            'from' => $from->toDateString(),
            'until' => $until->copy()->subDay()->toDateString(),
        ];
    }

    /**
     * How dark a day's square is, from nothing to a full day.
     *
     * Against a working day rather than against the busiest day in the picture,
     * which is where this parts company with GitHub. Their scale is relative
     * because there is no such thing as a normal number of commits; here there
     * is — the darkest square means "a full day", and it goes on meaning that
     * in a quiet month as much as in a busy one. A relative scale would paint
     * an afternoon black in the week somebody was ill.
     */
    private function level(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        return min(4, (int) ceil($seconds / (2 * 3600)));
    }

    /**
     * The same week for everybody in the workspace, one line per member.
     *
     * For whoever holds the right to look — see WorkspaceAbility::SeeHours.
     * Totals and whether somebody is clocked in right now, and deliberately not
     * the individual stretches: this answers "hoe staat het ervoor", and a
     * colleague's exact comings and goings is a different question that nobody
     * has asked for yet.
     *
     * @return list<array{id: int, name: string, seconds: int, running: bool, since: string|null}>
     */
    public function forWorkspace(Workspace $workspace, User $reader, ?Carbon $anchor = null): array
    {
        [$from, $until] = $this->week($reader, $anchor);

        // A day either side of the reader's own week, so a colleague whose week
        // begins in another zone still has both of its edges in hand before
        // each member's own window trims the result.
        $entries = $workspace->timeEntries()
            ->overlapping($from->copy()->subDay(), $until->copy()->addDay())
            ->with('user:id,name,timezone')
            ->get();

        return $entries
            ->groupBy('user_id')
            ->map(function (Collection $ofMember) use ($anchor): array {
                /** @var TimeEntry $first */
                $first = $ofMember->first();
                $member = $first->user;

                /*
                 * Each member's week is read on their own clock, not the
                 * reader's. Somebody in Lissabon whose Monday began an hour
                 * later than yours still worked a Monday, and the overlapping
                 * query is deliberately generous so that both zones' editions
                 * of the week come back and each is then trimmed to its own.
                 */
                [$memberFrom, $memberUntil] = $this->week($member, $anchor);

                $counted = $ofMember->filter(
                    fn (TimeEntry $entry): bool => $entry->localDate($member) >= $memberFrom->toDateString()
                        && $entry->localDate($member) < $memberUntil->toDateString()
                );

                $running = $ofMember->first(fn (TimeEntry $entry): bool => $entry->isRunning());

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'seconds' => $this->total($counted),
                    'running' => $running !== null,
                    'since' => $running?->started_at->toIso8601String(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * One stretch as the screen needs it.
     *
     * The times go out as instants and as the member's own wall clock both. The
     * browser could convert, but it would convert to the browser's zone — and
     * somebody sitting in a hotel in Lissabon looking at their Dutch working
     * week wants the hours they wrote down, not the hours it was where they are
     * standing.
     *
     * @return array<string, mixed>
     */
    private function present(TimeEntry $entry, User $member): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->localDate($member),
            'startedAt' => $entry->started_at->toIso8601String(),
            'endedAt' => $entry->ended_at?->toIso8601String(),
            'startedTime' => $entry->onMemberClock($entry->started_at, $member)->format('H:i'),
            'endedTime' => $entry->ended_at === null
                ? null
                : $entry->onMemberClock($entry->ended_at, $member)->format('H:i'),
            'seconds' => $entry->seconds(),
            'running' => $entry->isRunning(),
            'corrected' => $entry->wasCorrected(),
            /*
             * So the screen can say that a stretch is longer than anything a
             * day explains, and that only part of it counts. Usually the sign
             * of a shift somebody forgot to close rather than one they worked.
             */
            'overLimit' => $entry->seconds() >= TimeEntry::MAX_SHIFT_HOURS * 3600,
        ];
    }

    /**
     * The Monday-to-Monday window around a moment, on the member's clock.
     *
     * Handed back as instants, because that is what the query compares against;
     * they are the exact moments that week began and ended where the member
     * was.
     *
     * @return array{Carbon, Carbon}
     */
    private function week(User $member, ?Carbon $anchor = null): array
    {
        $local = $member->localNow($anchor);

        $from = $local->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [$from, $from->copy()->addWeek()];
    }

    /** @param  Collection<int, TimeEntry>  $entries */
    private function total(Collection $entries): int
    {
        return (int) $entries->sum(fn (TimeEntry $entry): int => $entry->seconds());
    }
}
