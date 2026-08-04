import { router } from '@inertiajs/react';
import { BarChart3, Check, Lock } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    close as closePoll,
    reopen as reopenPoll,
    vote as castVote,
} from '@/routes/chat/polls';
import type { MessagePollCard } from '@/types/chat';

/**
 * A question put to the channel, with where the votes stand.
 *
 * Clicking an answer votes straight away — there is no separate submit, because
 * there is nothing to review: one click is the whole gesture, and clicking your
 * own answer again takes the vote off.
 *
 * Who voted for what is shown rather than hidden. That is what this feature
 * decided a poll is, and the honest thing is to say so before somebody clicks
 * rather than after, which is what the line under an open poll does.
 */
export function PollCard({
    card,
    workspaceSlug,
    currentUserId,
}: {
    card: MessagePollCard;
    workspaceSlug: string;
    /**
     * Which answers are yours, and whether the poll is — neither can be in the
     * payload, which is broadcast to everybody at once. See PresentMessage.
     */
    currentUserId: number;
}) {
    const { t, tChoice } = useTranslate();
    const total = card.voterCount;
    const askedByMe = card.askedBy === currentUserId;

    return (
        <div className="mt-1.5 max-w-lg rounded-lg border border-l-2 border-l-primary/40 p-3">
            <div className="flex items-start gap-2">
                <BarChart3 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <p className="min-w-0 flex-1 text-sm font-medium">
                    {card.question}
                </p>
                {card.isClosed && (
                    <Lock
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-label={
                            card.state === 'closed'
                                ? t('chat_ui.poll.closed')
                                : t('chat_ui.poll.expired')
                        }
                    />
                )}
            </div>

            <ul className="mt-2 space-y-1">
                {card.options.map((option) => {
                    const mine = option.voters.some(
                        (voter) => voter.id === currentUserId,
                    );
                    // Share of the people who answered, not of the ticks: on a
                    // multiple-choice poll those are different numbers and only
                    // the first one reads as a percentage anybody means.
                    const share =
                        total === 0
                            ? 0
                            : Math.round((option.voters.length / total) * 100);

                    return (
                        <li key={option.id}>
                            <button
                                type="button"
                                disabled={card.isClosed}
                                onClick={() =>
                                    router.post(
                                        castVote.url({
                                            workspace: workspaceSlug,
                                            poll: card.id,
                                            option: option.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                title={
                                    option.voters.length > 0
                                        ? option.voters
                                              .map((voter) => voter.name)
                                              .join(', ')
                                        : undefined
                                }
                                className={cn(
                                    'relative w-full overflow-hidden rounded-md border px-2.5 py-1.5 text-left text-sm transition-colors',
                                    card.isClosed
                                        ? 'cursor-default'
                                        : 'hover:bg-muted/50',
                                    mine && 'border-primary/50',
                                )}
                            >
                                {/*
                                    The bar is behind the text rather than under
                                    it: a row that grows a second line when
                                    somebody votes makes the whole message jump.
                                */}
                                <span
                                    aria-hidden
                                    className="absolute inset-y-0 left-0 bg-primary/10 transition-[width]"
                                    style={{ width: `${share}%` }}
                                />
                                <span className="relative flex items-center gap-2">
                                    {mine && (
                                        <Check className="size-3.5 shrink-0 text-primary" />
                                    )}
                                    <span className="min-w-0 flex-1 truncate">
                                        {option.label}
                                    </span>
                                    <VoterStack voters={option.voters} />
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {option.voters.length}
                                    </span>
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            <p className="mt-2 text-xs text-muted-foreground">
                {total === 0
                    ? t('chat_ui.poll.no_votes')
                    : tChoice('chat_ui.poll.votes', total)}
                {card.allowsMultiple && ` · ${t('chat_ui.poll.multiple')}`}
                {card.state === 'closed' &&
                    ` · ${t('chat_ui.poll.state_closed')}`}
                {card.state === 'expired' &&
                    ` · ${t('chat_ui.poll.state_expired')}`}
                {/*
                    Said while it still matters. Somebody who finds out
                    afterwards that their vote was public has been told too
                    late.
                */}
                {!card.isClosed && ` · ${t('chat_ui.poll.public_note')}`}
            </p>

            {/*
                Only to the person who asked. Reopening is offered as plainly
                as closing was: somebody who stopped a poll a minute too early,
                or set the deadline a day short, should not have to ask the
                question again — and asking again would throw away the answers
                people already gave.
            */}
            {askedByMe &&
                (card.isClosed ? (
                    <button
                        type="button"
                        onClick={() =>
                            router.post(
                                reopenPoll.url({
                                    workspace: workspaceSlug,
                                    poll: card.id,
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:underline"
                    >
                        {t('chat_ui.poll.reopen')}
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={() =>
                            router.delete(
                                closePoll.url({
                                    workspace: workspaceSlug,
                                    poll: card.id,
                                }),
                                { preserveScroll: true },
                            )
                        }
                        className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:underline"
                    >
                        {t('chat_ui.poll.close')}
                    </button>
                ))}
        </div>
    );
}

/** How many faces fit next to an answer before the row starts reading as a crowd. */
const FACES_SHOWN = 4;

/**
 * The people behind an answer, overlapping.
 *
 * Faces rather than a number alone, because in this feature a vote is
 * attributable and the useful question in a channel is usually "who is going
 * on Tuesday" rather than "how many". The count stays beside the stack: past
 * four people the faces stop being countable and only the number still says
 * how many there are.
 *
 * The row must not grow when somebody votes — that would shove the rest of the
 * message down — so the avatars are sized under the line height and the stack
 * is capped rather than wrapped.
 */
function VoterStack({
    voters,
}: {
    voters: MessagePollCard['options'][number]['voters'];
}) {
    const getInitials = useInitials();

    if (voters.length === 0) {
        return null;
    }

    const shown = voters.slice(0, FACES_SHOWN);
    const rest = voters.length - shown.length;

    return (
        <span className="flex shrink-0 -space-x-1.5">
            {shown.map((voter) => (
                <Avatar
                    key={voter.id}
                    /*
                        The ring is what keeps two faces apart where they
                        overlap; without it the stack reads as one smudge.
                    */
                    className="size-5 ring-2 ring-background"
                    title={voter.name ?? undefined}
                >
                    {voter.avatarUrl && (
                        <AvatarImage src={voter.avatarUrl} alt="" />
                    )}
                    <AvatarFallback className="text-[9px] font-semibold">
                        {getInitials(voter.name ?? '')}
                    </AvatarFallback>
                </Avatar>
            ))}

            {rest > 0 && (
                <span className="flex size-5 items-center justify-center rounded-full bg-muted text-[9px] font-semibold text-muted-foreground ring-2 ring-background">
                    +{rest}
                </span>
            )}
        </span>
    );
}
