<?php

namespace App\Enums;

/**
 * Why something ended up in a member's inbox.
 *
 * Five ways a conversation can ask for you, kept apart so the inbox can say
 * which one it was and so a filter can leave the others out. The distinction
 * matters most between Reply and ThreadReply: being answered directly is a
 * question put to you, while a thread you once spoke in carrying on is news —
 * the same row shape, but not the same claim on somebody's attention.
 */
enum InboxItemType: string
{
    /** Somebody wrote your handle, or reached you through @everyone or @here. */
    case Mention = 'mention';

    /** Somebody replied to a message you wrote. */
    case Reply = 'reply';

    /** A thread you have spoken in carried on without addressing you. */
    case ThreadReply = 'thread-reply';

    /** Somebody answered a poll you asked. */
    case PollVote = 'poll-vote';

    /**
     * A contract you sent out came back — somebody signed it, somebody refused,
     * or the last person has been round.
     *
     * One case for all three rather than three, because they are the same claim
     * on the same person's attention: the thing you sent out has moved. Which
     * of the three it was is in the message the row points at, where a person
     * reads it, rather than in a filter nobody would use.
     */
    case ContractProgress = 'contract-progress';

    public function label(): string
    {
        return match ($this) {
            self::Mention => __('enums.inbox-item-type.label.Mention'),
            self::Reply => __('enums.inbox-item-type.label.Reply'),
            self::ThreadReply => __('enums.inbox-item-type.label.ThreadReply'),
            self::PollVote => __('enums.inbox-item-type.label.PollVote'),
            self::ContractProgress => __('enums.inbox-item-type.label.ContractProgress'),
        };
    }
}
