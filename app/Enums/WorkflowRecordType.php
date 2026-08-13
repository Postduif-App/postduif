<?php

namespace App\Enums;

use App\Models\Channel;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Poll;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of thing a step can be pointed at.
 *
 * The workflow builder started out able to name three things: a channel, a
 * person, a form. Everything else a workspace has since grown — tickets,
 * contracts, documents, polls — is a thing a step will want to act on, and each
 * of them would otherwise arrive as its own field type, its own picker, its own
 * lookup, and its own chance to forget that a workflow may only ever touch what
 * its own workspace owns.
 *
 * So one type, and a case per kind of record. Adding the next one is a case and
 * three match arms, and the picker, the validation and the runner all learn
 * about it at once.
 *
 * Tickets first, contracts second, and the second one is what the shape was
 * written for: adding them was a case, three match arms and nothing else — no
 * new control in the builder, no new validation, no second place where a
 * workspace boundary could be forgotten.
 */
enum WorkflowRecordType: string
{
    case Ticket = 'ticket';

    /** A contract of this workspace, template excluded. */
    case Contract = 'contract';

    /**
     * And a template, which is a different list on purpose.
     *
     * They live in the same table and would be one case if the picker were the
     * only consideration. It is not: an action that reminds somebody wants a
     * contract that is out, an action that sends one wants a mould that never
     * is, and offering either list where the other belongs is offering a choice
     * that cannot work — a template has nobody to remind, and a sent contract
     * cannot be sent again.
     */
    case ContractTemplate = 'contract-template';

    /** A document of this workspace. */
    case Document = 'document';

    /** A poll of this workspace. */
    case Poll = 'poll';

    /**
     * How many the picker offers.
     *
     * A workspace has hundreds of tickets and a dropdown is not a search, so
     * the list is the most recent handful. Anything older is reachable the way
     * the interesting case already is: with a variable, or by leaving the field
     * alone and letting the step act on whatever the trigger brought.
     */
    private const MAX_OPTIONS = 50;

    public function label(): string
    {
        return match ($this) {
            self::Ticket => __('enums.workflow-record-type.label.Ticket'),
            self::Contract => __('enums.workflow-record-type.label.Contract'),
            self::ContractTemplate => __('enums.workflow-record-type.label.ContractTemplate'),
            self::Document => __('enums.workflow-record-type.label.Document'),
            self::Poll => __('enums.workflow-record-type.label.Poll'),
        };
    }

    /**
     * Where the trigger leaves this kind of record, for a field left empty.
     *
     * The convention FindsTargets::message() already runs on, written down
     * rather than repeated: "herinner het contract dat zojuist verstuurd is"
     * should not need anything picked or typed, because the workflow was set
     * off by that contract and there is nothing else it could sensibly mean.
     */
    public function triggerPath(): string
    {
        /*
         * A template is never what a trigger was about — nothing happens to a
         * mould — so it points at the contract half of the payload too, where
         * it will find nothing and say so. Better a clear "geen sjabloon
         * gekozen" than a path that quietly resolves to the contract that set
         * the workflow off and sends the wrong document.
         */
        return match ($this) {
            self::ContractTemplate => 'trigger.contract_template.id',
            default => "trigger.{$this->value}.id",
        };
    }

    /**
     * The record this workspace has under that id, or null.
     *
     * The scoping is the whole method. Every path into a record goes through
     * here, so there is one place that decides a workflow cannot reach into
     * another workspace — and no action can be the one that forgot.
     */
    public function find(Workspace $workspace, string $id): ?Model
    {
        return match ($this) {
            self::Ticket => Ticket::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first(),

            /*
             * The template flag is part of the lookup rather than checked
             * afterwards: a step that named a template where a contract belongs
             * should find nothing, not find something and be told off later.
             */
            self::Contract => Contract::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_template', false)
                ->whereKey($id)
                ->first(),

            self::ContractTemplate => Contract::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_template', true)
                ->whereKey($id)
                ->first(),

            /*
             * Not withTrashed. A document that was removed can still be
             * described by a trigger — the row is there — but a step pointed at
             * one should find nothing: appending to a deleted document is
             * writing into a drawer nobody opens.
             */
            self::Document => Document::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first(),

            self::Poll => Poll::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first(),
        };
    }

    /**
     * What the picker offers, as value => what it reads as.
     *
     * Only what this member may see. A beheerder writing a workflow is not
     * automatically in every channel, and a dropdown is a poor place to learn
     * the title of a ticket from a channel nobody put you in.
     *
     * @return array<string, string>
     */
    public function options(Workspace $workspace, User $viewer): array
    {
        return match ($this) {
            self::Ticket => Ticket::query()
                ->where('workspace_id', $workspace->id)
                ->visibleTo($viewer)
                ->latest('id')
                ->limit(self::MAX_OPTIONS)
                ->get()
                ->mapWithKeys(fn (Ticket $ticket): array => [
                    (string) $ticket->getKey() => "#{$ticket->number} {$ticket->title}",
                ])
                ->all(),

            /*
             * No visibility scope on either of these, unlike the ticket. A
             * contract is not read through a channel — see ContractPolicy,
             * which asks the workspace and the author rather than the board —
             * and the person writing a workflow is a beheerder of this
             * workspace. What they may then do with the one they picked is
             * still asked again when the step runs.
             */
            self::Contract => Contract::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_template', false)
                ->latest('id')
                ->limit(self::MAX_OPTIONS)
                ->get()
                ->mapWithKeys(fn (Contract $contract): array => [
                    (string) $contract->getKey() => $contract->title,
                ])
                ->all(),

            self::ContractTemplate => Contract::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_template', true)
                ->latest('id')
                ->limit(self::MAX_OPTIONS)
                ->get()
                ->mapWithKeys(fn (Contract $contract): array => [
                    (string) $contract->getKey() => $contract->title,
                ])
                ->all(),

            // Both of these live in a channel, so both are scoped to what this
            // member may see — the same reason the ticket list is.
            self::Document => Document::query()
                ->where('workspace_id', $workspace->id)
                ->visibleTo($viewer)
                ->latest('id')
                ->limit(self::MAX_OPTIONS)
                ->get()
                ->mapWithKeys(fn (Document $document): array => [
                    (string) $document->getKey() => "#{$document->number} {$document->title}",
                ])
                ->all(),

            self::Poll => Poll::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('channel_id', Channel::query()->visibleTo($viewer)->select('id'))
                ->latest('id')
                ->limit(self::MAX_OPTIONS)
                ->get()
                ->mapWithKeys(fn (Poll $poll): array => [
                    (string) $poll->getKey() => $poll->question,
                ])
                ->all(),
        };
    }
}
