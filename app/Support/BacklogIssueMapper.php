<?php

namespace App\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;

/**
 * Translates between Backlog's idea of an issue and Postduif's idea of a
 * ticket.
 *
 * Neither scale lines up one to one with the other, so both directions are
 * lossy on purpose rather than by accident — see each method for which
 * distinctions get folded together and why.
 *
 * ## Priority
 *
 * Backlog's own priorities run 0 (no priority) through 4 (low), with 0 sorting
 * below Low rather than above Urgent — see Backlog's `Priority::sortWeight()`.
 * Postduif has no "no priority" case, so 0 folds into Normal: a ticket nobody
 * has judged reads as ordinary rather than as either extreme.
 *
 * | Backlog       | 0 (none) | 1 (urgent) | 2 (high) | 3 (medium) | 4 (low) |
 * |---------------|----------|------------|----------|------------|---------|
 * | Postduif      | Normal   | Urgent     | High     | Normal     | Low     |
 *
 * The reverse direction (Postduif → Backlog, for the outbound sync in
 * SyncTicketToBacklogJob) sends 3 (medium) for Normal rather than 0: there is
 * no way back to "nobody has judged this yet" from a scale with no such case,
 * and asserting that over something a person here may since have judged would
 * be a stronger claim than this sync is entitled to make.
 *
 * ## Status
 *
 * Backlog's workflow states are grouped into categories the way Linear's are —
 * backlog, unstarted, started, completed, cancelled — and it is the category
 * rather than a specific state's name that this maps from, since a workspace
 * can rename or add states within a category. An unrecognised or missing
 * category reads as Open rather than throwing: an issue this server cannot yet
 * classify is still one that needs attention, not one to lose.
 *
 * | Backlog category            | Postduif status |
 * |------------------------------|-----------------|
 * | backlog, unstarted, (other)  | Open            |
 * | started                      | InProgress      |
 * | completed                    | Resolved        |
 * | cancelled, canceled          | Closed          |
 *
 * The reverse direction (Postduif → Backlog, for the outbound sync in
 * SyncTicketToBacklogJob) only ever sends a category, never a specific state
 * id: Backlog is the side that owns which state within a category an issue
 * lands on.
 */
class BacklogIssueMapper
{
    /**
     * @param  mixed  $priority  The `priority` field of a Backlog IssueResource
     *                           payload — an integer 0 through 4.
     */
    public static function toTicketPriority(mixed $priority): TicketPriority
    {
        return match ((int) $priority) {
            1 => TicketPriority::Urgent,
            2 => TicketPriority::High,
            4 => TicketPriority::Low,
            // 0 (none) and 3 (medium) both read as ordinary — see the class
            // docblock — and so does anything this server does not recognise.
            default => TicketPriority::Normal,
        };
    }

    /**
     * @param  mixed  $status  The `status` field of a Backlog IssueResource
     *                         payload: either `{category: string, ...}` or a
     *                         bare category string.
     */
    public static function toTicketStatus(mixed $status): TicketStatus
    {
        $category = is_array($status) ? ($status['category'] ?? null) : $status;
        $category = is_string($category) ? strtolower($category) : null;

        return match ($category) {
            'started' => TicketStatus::InProgress,
            'completed' => TicketStatus::Resolved,
            'cancelled', 'canceled' => TicketStatus::Closed,
            default => TicketStatus::Open,
        };
    }

    /**
     * The Backlog workflow-state category a ticket status corresponds to, for
     * telling Backlog about a status change made on this side.
     */
    public static function toBacklogCategory(TicketStatus $status): string
    {
        return match ($status) {
            TicketStatus::Open => 'unstarted',
            TicketStatus::InProgress, TicketStatus::Waiting => 'started',
            TicketStatus::Resolved => 'completed',
            TicketStatus::Closed => 'cancelled',
        };
    }

    /**
     * The Backlog priority a Postduif priority corresponds to, for telling
     * Backlog about a priority change made on this side — see the class
     * docblock for why Normal sends medium rather than none.
     */
    public static function toBacklogPriority(TicketPriority $priority): int
    {
        return match ($priority) {
            TicketPriority::Urgent => 1,
            TicketPriority::High => 2,
            TicketPriority::Normal => 3,
            TicketPriority::Low => 4,
        };
    }
}
