<?php

namespace App\Enums;

/**
 * Where a contract stands.
 *
 * Five, and the split that matters is not "done or not" but whether the thing
 * is still asking something of somebody. A draft asks its author to finish it,
 * a sent contract asks the signers to sign, and the other three ask nothing of
 * anyone — which is why they are the three that the prune command may touch and
 * the reminder button may not.
 *
 * Cancelled and expired are kept apart although both mean "this link is dead".
 * The person holding the link has to be told which: "de aanvrager heeft dit
 * ingetrokken" and "dit is verlopen" lead to different next steps, and a single
 * closed state would force the public page to say neither.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Whether somebody is still expected to act.
     *
     * What the overview filters on, and what decides whether a reminder is a
     * sensible thing to offer. Draft counts: the person who has to act is the
     * author, and a draft left standing for a fortnight is exactly the thing
     * that should still show up in their list.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    /**
     * Whether the link still opens anything.
     *
     * Only Sent. A completed contract keeps its record but its tokens are spent,
     * and a draft has no tokens at all — nothing was handed out yet.
     */
    public function isSignable(): bool
    {
        return $this === self::Sent;
    }

    /**
     * Whether this one is a record rather than a request.
     *
     * The question the prune command asks. A completed contract is the piece of
     * evidence the whole feature exists to produce, so it never goes on a timer;
     * the other endings are correspondence that fizzled out.
     */
    public function isEvidence(): bool
    {
        return $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('enums.contract-status.label.Draft'),
            self::Sent => __('enums.contract-status.label.Sent'),
            self::Completed => __('enums.contract-status.label.Completed'),
            self::Cancelled => __('enums.contract-status.label.Cancelled'),
            self::Expired => __('enums.contract-status.label.Expired'),
        };
    }

    /**
     * What the state means for whoever is looking at it, in a sentence.
     *
     * Beside the label for the reason TicketStatus carries one: a word like
     * "verlopen" says what happened but not what anybody can still do about it,
     * and that is the only thing a person reads a status for.
     */
    public function description(): string
    {
        return match ($this) {
            self::Draft => __('enums.contract-status.description.Draft'),
            self::Sent => __('enums.contract-status.description.Sent'),
            self::Completed => __('enums.contract-status.description.Completed'),
            self::Cancelled => __('enums.contract-status.description.Cancelled'),
            self::Expired => __('enums.contract-status.description.Expired'),
        };
    }
}
