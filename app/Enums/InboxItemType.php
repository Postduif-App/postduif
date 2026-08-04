<?php

namespace App\Enums;

/**
 * Why something ended up in a member's inbox.
 *
 * Four ways a conversation can ask for you, kept apart so the inbox can say
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

    public function label(): string
    {
        return match ($this) {
            self::Mention => __('enums.inbox-item-type.label.Mention'),
            self::Reply => __('enums.inbox-item-type.label.Reply'),
            self::ThreadReply => __('enums.inbox-item-type.label.ThreadReply'),
            self::PollVote => __('enums.inbox-item-type.label.PollVote'),
        };
    }
}
