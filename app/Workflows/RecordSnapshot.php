<?php

namespace App\Workflows;

use App\Enums\WorkflowRecordType;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Document;
use App\Models\Poll;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What a record looks like to a workflow, right now.
 *
 * Pulled out of the listeners because there are two readers of it rather than
 * one. A trigger describes the record as it was when something happened to it;
 * a Read step describes the same record as it stands at the moment the step
 * runs — and the only way the second is useful is if it produces the same
 * paths as the first. A workflow that waited three days and then read
 * {{ steps.2.contract.signed_count }} is only readable at all because it is
 * spelled exactly like {{ trigger.contract.signed_count }}.
 *
 * Two spellings of "what a contract is" would drift, and the first anybody
 * would hear of it is a condition that compares against a path one half of the
 * application does not fill in. So: one place that says what a record is, one
 * place that says what it is called, and the triggers and the steps both read
 * from it.
 *
 * What is *not* here is anything about a happening — the actor, the signer,
 * whether a vote was ticked or unticked. Those belong to the moment rather than
 * to the record, and a step re-reading a contract has no actor to name.
 */
final class RecordSnapshot
{
    /**
     * The record as it stands, keyed the way a trigger keys it.
     *
     * @return array<string, mixed>
     */
    public static function of(Model $record): array
    {
        return match (true) {
            $record instanceof Ticket => self::ticket($record),
            $record instanceof Contract => self::contract($record),
            $record instanceof Document => self::document($record),
            $record instanceof Poll => self::poll($record),
            default => throw new InvalidArgumentException(
                'No workflow snapshot for '.$record::class,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        /*
         * The opener as well, which openedByName() reaches for below. Missed on
         * the first writing of this because every caller then was a listener
         * that had eager-loaded it — and a loop walking fifty tickets it looked
         * up itself is exactly the caller that had not.
         */
        $ticket->loadMissing(['assignee:id,name', 'opener:id,name', 'channel:id,name']);

        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'title' => $ticket->title,
                'body' => $ticket->body,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
                'due_at' => $ticket->due_at?->toIso8601String(),
                // Whole hours: a condition comparing against 24 should not have
                // to reason about minutes.
                'hours_open' => (int) $ticket->created_at?->diffInHours(now()),
                'is_overdue' => $ticket->due_at !== null && $ticket->due_at->isPast(),
                'has_assignee' => $ticket->assigned_to !== null,
                // Whether anybody has answered the person who raised it, which
                // is the number a customer channel is actually judged on.
                'answered' => $ticket->first_responded_at !== null,
            ],
            'assignee' => ['id' => $ticket->assigned_to, 'name' => $ticket->assignee?->name],
            'reporter' => ['id' => $ticket->opened_by, 'name' => $ticket->openedByName()],
            'channel' => ['id' => $ticket->channel_id, 'name' => $ticket->channel?->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contract(Contract $contract): array
    {
        /*
         * Loaded here rather than assumed, because this is now read from two
         * directions: a listener that eager-loaded the signers, and a step that
         * found the contract by id a moment ago. loadMissing leaves the first
         * alone and rescues the second.
         */
        $contract->loadMissing(['signers', 'author:id,name', 'notifyChannel:id,name', 'workspace']);

        $signed = $contract->signers->filter(fn (ContractSigner $one): bool => $one->hasSigned())->count();
        $declined = $contract->signers->filter(fn (ContractSigner $one): bool => $one->hasDeclined())->count();

        return [
            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'url' => route('chat.contracts.show', [$contract->workspace, $contract]),
                'expires_at' => $contract->expires_at?->toDateString(),
                /*
                 * Null rather than a number when there is no deadline, so a
                 * condition asking "binnen drie dagen" says no for a contract
                 * that can never run out — where a 0 or a 999 would both be a
                 * lie in one direction or the other.
                 *
                 * Whole days and never negative: a contract that ran out
                 * yesterday is at nought, which is what "nog nul dagen" means
                 * to whoever reads it.
                 *
                 * And this is the line that makes a Read step worth having.
                 * Worked out against now() rather than stored, so re-reading
                 * after a Delay gives the number as it is today rather than the
                 * one the trigger saw.
                 */
                'days_until_expiry' => $contract->expires_at === null
                    ? null
                    : max(0, (int) now()->startOfDay()->diffInDays($contract->expires_at->startOfDay(), false)),
                'page_count' => $contract->page_count,
                'signer_count' => $contract->signers->count(),
                'signed_count' => $signed,
                'declined_count' => $declined,
                'remaining' => $contract->signers->count() - $signed - $declined,

                // The names in one string, for a message that wants to say who
                // it is about without a step per person.
                'signers' => $contract->signers->pluck('name')->implode(', '),
                'download_url' => $contract->status->isEvidence()
                    ? route('chat.contracts.download', [$contract->workspace, $contract])
                    : null,
            ],
            'author' => [
                'id' => $contract->created_by,
                'name' => $contract->author?->name,
            ],
            /*
             * The channel news about this contract is posted in, which is
             * usually where a workflow wants to answer as well — and empty for
             * a contract that has none, so a step pointed at
             * {{ trigger.channel.id }} fails visibly rather than posting
             * somewhere nobody chose.
             */
            'channel' => [
                'id' => $contract->notify_channel_id,
                'name' => $contract->notifyChannel?->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $document): array
    {
        $document->loadMissing('channel:id,name');

        return [
            /*
             * No url. A document is a tab inside a channel rather than a page
             * of its own, so there is no address to hand anybody — the number
             * and the channel are how people refer to one, and that is what a
             * message written by a workflow can say.
             */
            'document' => [
                'id' => $document->id,
                'number' => $document->number,
                'title' => $document->title,
            ],
            'channel' => ['id' => $document->channel_id, 'name' => $document->channel->name],
        ];
    }

    /**
     * The tally, which is the whole reason a poll is worth re-reading.
     *
     * The counts are loaded here rather than taken from whatever the caller
     * happened to eager-load. A relation that is already there without its
     * count is worse than one that is missing — loadMissing would leave it
     * alone and every number below would come out empty — so this one asks
     * outright, and pays a query for being right from both directions.
     *
     * @return array<string, mixed>
     */
    public static function poll(Poll $poll): array
    {
        $poll->load(['options' => fn ($query) => $query->withCount('votes')]);
        $poll->loadMissing(['asker:id,name', 'channel:id,name', 'workspace']);

        $leader = $poll->options->sortByDesc('votes_count')->first();

        return [
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'url' => route('chat.polls.show', [$poll->workspace, $poll]),
                'option_count' => $poll->options->count(),
                'vote_count' => $poll->options->sum('votes_count'),
                // How many people answered, which on a multiple-choice poll is
                // not how many votes were cast — see Poll::voterCount.
                'voter_count' => $poll->voterCount(),
                /*
                 * The one in front and how many it has. Two paths rather than a
                 * list, because what a workflow says out loud is nearly always
                 * "X ligt voor met Y" — and because a condition can compare
                 * top_votes and cannot compare a list.
                 *
                 * Nothing about ties: two answers on four votes each will name
                 * one of them, and which one is the order the options were
                 * written in.
                 */
                'leading_option' => $leader?->label,
                'top_votes' => $leader === null ? 0 : $leader->votes_count,
                'is_closed' => $poll->isClosed(),
                'closes_at' => $poll->closes_at?->toIso8601String(),
            ],
            'asker' => ['id' => $poll->created_by, 'name' => $poll->asker?->name],
            'channel' => ['id' => $poll->channel_id, 'name' => $poll->channel?->name],
        ];
    }

    /**
     * What the paths above are called, for a trigger's or a step's provides().
     *
     * Beside the values rather than in the trigger that used to own them, for
     * the reason this class exists at all: a path that is filled in here and
     * described somewhere else is a path that can be quietly dropped from one
     * and not the other.
     *
     * @return array<string, string>
     */
    public static function paths(WorkflowRecordType $type): array
    {
        return match ($type) {
            WorkflowRecordType::Ticket => [
                'ticket.id' => __('workflows.provides.ticket.id'),
                'ticket.number' => __('workflows.provides.ticket.number'),
                'ticket.title' => __('workflows.provides.ticket.title'),
                'ticket.body' => __('workflows.provides.ticket.body'),
                'ticket.status' => __('workflows.provides.ticket.status'),
                'ticket.priority' => __('workflows.provides.ticket.priority'),
                'ticket.due_at' => __('workflows.provides.ticket.due_at'),
                'ticket.hours_open' => __('workflows.provides.ticket.hours_open'),
                'ticket.is_overdue' => __('workflows.provides.ticket.is_overdue'),
                'ticket.has_assignee' => __('workflows.provides.ticket.has_assignee'),
                'ticket.answered' => __('workflows.provides.ticket.answered'),
                'assignee.id' => __('workflows.provides.ticket.assignee_id'),
                'assignee.name' => __('workflows.provides.ticket.assignee_name'),
                'reporter.id' => __('workflows.provides.ticket.reporter_id'),
                'reporter.name' => __('workflows.provides.ticket.reporter_name'),
                'channel.id' => __('workflows.provides.channel.id'),
                'channel.name' => __('workflows.provides.channel.name'),
            ],

            /*
             * A template has no snapshot of its own: nothing happens to a
             * mould, and re-reading one would tell a workflow what it already
             * knew. It falls to the contract shape so that the enum stays
             * exhaustive rather than growing a case that throws.
             */
            WorkflowRecordType::Contract, WorkflowRecordType::ContractTemplate => [
                'contract.id' => __('workflows.provides.contract.id'),
                'contract.title' => __('workflows.provides.contract.title'),
                'contract.status' => __('workflows.provides.contract.status'),
                'contract.url' => __('workflows.provides.contract.url'),
                'contract.expires_at' => __('workflows.provides.contract.expires_at'),
                'contract.days_until_expiry' => __('workflows.provides.contract.days_until_expiry'),
                'contract.page_count' => __('workflows.provides.contract.page_count'),
                'contract.signer_count' => __('workflows.provides.contract.signer_count'),
                'contract.signed_count' => __('workflows.provides.contract.signed_count'),
                'contract.declined_count' => __('workflows.provides.contract.declined_count'),
                'contract.remaining' => __('workflows.provides.contract.remaining'),
                'contract.signers' => __('workflows.provides.contract.signers'),
                'author.id' => __('workflows.provides.contract.author_id'),
                'author.name' => __('workflows.provides.contract.author_name'),
                'channel.id' => __('workflows.provides.contract.channel_id'),
                'channel.name' => __('workflows.provides.contract.channel_name'),
            ],

            WorkflowRecordType::Document => [
                'document.id' => __('workflows.provides.document.id'),
                'document.number' => __('workflows.provides.document.number'),
                'document.title' => __('workflows.provides.document.title'),
                'channel.id' => __('workflows.provides.channel.id'),
                'channel.name' => __('workflows.provides.channel.name'),
            ],

            WorkflowRecordType::Poll => [
                'poll.id' => __('workflows.provides.poll.id'),
                'poll.question' => __('workflows.provides.poll.question'),
                'poll.url' => __('workflows.provides.poll.url'),
                'poll.option_count' => __('workflows.provides.poll.option_count'),
                'poll.vote_count' => __('workflows.provides.poll.vote_count'),
                'poll.voter_count' => __('workflows.provides.poll.voter_count'),
                'poll.leading_option' => __('workflows.provides.poll.leading_option'),
                'poll.top_votes' => __('workflows.provides.poll.top_votes'),
                'poll.is_closed' => __('workflows.provides.poll.is_closed'),
                'poll.closes_at' => __('workflows.provides.poll.closes_at'),
                'asker.id' => __('workflows.provides.poll.asker_id'),
                'asker.name' => __('workflows.provides.poll.asker_name'),
                'channel.id' => __('workflows.provides.channel.id'),
                'channel.name' => __('workflows.provides.channel.name'),
            ],

            /*
             * Nothing, and that is not an oversight. A share is the one record
             * type here that a step only ever acts on — see the sever step — and
             * never reads context out of: of() above has no arm for it either,
             * so there is no snapshot for these paths to describe. An arm that
             * invented some would offer a workflow author variables that would
             * be empty in every run.
             */
            WorkflowRecordType::ChannelShare => [],
        };
    }
}
