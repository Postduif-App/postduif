<?php

namespace App\Enums;

/**
 * The happenings a workflow can be told to wait for.
 *
 * A short, named list rather than "any trigger", and the shortness is the
 * point. Waiting only means something when the thing waited for is *about* a
 * particular record — "wacht tot dit contract getekend is" — so a happening
 * that carries no record, or a different one every time, has nothing for a
 * waiting run to match itself against. A keyword in a message is not something
 * you can wait for; it is something you react to.
 *
 * Each case is the key of a trigger that already exists, so the resumer needs
 * no second vocabulary: the same string a workflow stores when it listens for
 * something is the string it stores when it waits for it.
 */
enum WorkflowAwaitableEvent: string
{
    case ContractSigned = 'contract-signed';
    case ContractCompleted = 'contract-completed';
    case ContractDeclined = 'contract-declined';
    case ContractCancelled = 'contract-cancelled';
    case ContractExpired = 'contract-expired';
    case ContractOpened = 'contract-opened';

    case TicketChanged = 'ticket-changed';
    case TicketCommented = 'ticket-commented';

    case PollVoted = 'poll-voted';
    case PollClosed = 'poll-closed';

    case DocumentDeleted = 'document-deleted';

    case ChannelShareAnswered = 'channel-share-answered';

    /**
     * Which kind of record this happening is about.
     *
     * The half that makes waiting possible: it says where to find the record's
     * id both in what the run remembers — trigger.contract.id — and in what the
     * happening carries when it eventually arrives. One answer read from two
     * directions, which is why it is a method here rather than a lookup in each.
     */
    public function record(): WorkflowRecordType
    {
        return match ($this) {
            self::ContractSigned,
            self::ContractCompleted,
            self::ContractDeclined,
            self::ContractCancelled,
            self::ContractExpired,
            self::ContractOpened => WorkflowRecordType::Contract,

            self::TicketChanged, self::TicketCommented => WorkflowRecordType::Ticket,

            self::PollVoted, self::PollClosed => WorkflowRecordType::Poll,

            self::DocumentDeleted => WorkflowRecordType::Document,

            self::ChannelShareAnswered => WorkflowRecordType::ChannelShare,
        };
    }

    /**
     * Where the record's id sits inside a happening's own payload.
     *
     * Without the "trigger." in front, because the resumer is handed the
     * payload itself rather than a run's memory of one — see
     * ResumeAwaitingWorkflows.
     */
    public function pathInHappening(): string
    {
        return "{$this->record()->value}.id";
    }

    /** How it reads where somebody picks one. */
    public function label(): string
    {
        return __("workflows.triggers.{$this->value}.label");
    }

    /**
     * The whole list as a picker wants it: key => how it reads.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $event): array => [$event->value => $event->label()])
            ->all();
    }
}
