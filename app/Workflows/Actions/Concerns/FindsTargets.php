<?php

namespace App\Workflows\Actions\Concerns;

use App\Enums\WorkflowRecordType;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Message;
use App\Models\Poll;
use App\Models\Ticket;
use App\Models\User;
use App\Workflows\WorkflowStepContext;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Finding the channel, the message or the person a step was pointed at.
 *
 * Shared because every one of these lookups has the same two halves, and the
 * second half is the one that matters: does it exist, and does it belong to
 * this workspace. Written once, so an action cannot be the one that forgot —
 * a workflow that could name an id from another workspace would be a way to
 * post into places nobody in this one can see.
 *
 * Every failure throws with a sentence somebody can read, because that sentence
 * is what ends up on the run screen. "Kanaal niet gevonden" is a complete
 * answer there in a way that a null would not be.
 */
trait FindsTargets
{
    /**
     * The channel a step names, when it belongs to this workspace and the
     * workflow's owner may see it.
     */
    protected function channel(WorkflowStepContext $context, string $key = 'channel_id'): Channel
    {
        $id = $context->setting($key);

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_channel_chosen'));
        }

        $channel = $this->findChannel($context, (string) $id);

        /*
         * One answer for "no such channel" and "not the owner's to see". They
         * are told apart nowhere else in this application either — see the MCP
         * tools — because telling them apart is a way to find out which ids
         * exist.
         */
        if ($channel === null || $this->actor($context)->cannot('view', $channel)) {
            throw new RuntimeException(__('workflows.errors.channel_not_found'));
        }

        return $channel;
    }

    /**
     * The channel a setting names: by id, or by the name people call it.
     *
     * A name as well as an id because of where these values now come from. A
     * field may hold a variable, and what a trigger knows is usually
     * trigger.channel.name — "meld dit in #storingen" is how somebody thinks
     * about it, and asking them to carry an id through a workflow would be
     * asking them to write something they cannot read back.
     *
     * The hash is stripped because people type it. It is punctuation in the
     * chat, not part of the name — the same rule the slash command applies to
     * its own leading slash.
     *
     * Scoped to the workflow's workspace in every branch, which is the property
     * that makes a variable safe here at all: whatever it resolves to, it can
     * only ever find something this workspace owns.
     */
    private function findChannel(WorkflowStepContext $context, string $named): ?Channel
    {
        $channels = Channel::query()->where('workspace_id', $context->workspace()->id);

        if (ctype_digit($named)) {
            return $channels->whereKey($named)->first();
        }

        $name = ltrim(trim($named), '#');

        /*
         * Case-insensitively, because a name is typed by a person and "#Storingen"
         * and "#storingen" are the same channel to everybody except a database.
         */
        return $channels->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    /**
     * The message a step names.
     *
     * Defaults to the one the trigger was about, which is what almost every
     * workflow means: pinning, replying and reacting are things you do to the
     * message that set the workflow off. A step may name another by writing a
     * variable into the field.
     */
    protected function message(WorkflowStepContext $context, string $key = 'message_id'): Message
    {
        $id = $context->setting($key);

        if (blank($id)) {
            $id = $context->value('trigger.message.id');
        }

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_message'));
        }

        $message = Message::query()
            ->where('workspace_id', $context->workspace()->id)
            ->whereKey($id)
            ->first();

        if ($message === null || $this->actor($context)->cannot('view', $message->channel)) {
            throw new RuntimeException(__('workflows.errors.message_not_found'));
        }

        return $message;
    }

    /**
     * A ticket, a contract, or whatever else a step can be pointed at.
     *
     * The generalisation of message() above, and it works the same way for the
     * same reason: an empty field means the record the trigger was about.
     * "Herinner het contract dat zojuist verstuurd is" is one step with nothing
     * filled in, and asking somebody to carry an id through a workflow they
     * cannot read back would be asking them to write something worse.
     *
     * Three refusals, all with a sentence that ends up on the run screen:
     * nothing named and nothing in the trigger, no such record in this
     * workspace, and not the owner's to see. The last two say the same thing
     * on purpose — telling them apart is a way to find out which ids exist.
     */
    protected function record(WorkflowStepContext $context, WorkflowRecordType $type, ?string $key = null): Model
    {
        $id = $context->setting($key ?? "{$type->value}_id");

        if (blank($id)) {
            $id = $context->value($type->triggerPath());
        }

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_record', ['what' => $type->label()]));
        }

        $record = $type->find($context->workspace(), (string) $id);

        if ($record === null || $this->actor($context)->cannot('view', $record)) {
            throw new RuntimeException(__('workflows.errors.record_not_found', ['what' => $type->label()]));
        }

        return $record;
    }

    /**
     * The same thing, said in a type the caller can use.
     *
     * The generic one hands back a Model, which is honest and useless: an
     * action that wants a ticket wants its number and its status. One line per
     * kind of record buys every action after it a typed answer, and the
     * scoping still happens in exactly one place.
     */
    protected function ticket(WorkflowStepContext $context, string $key = 'ticket_id'): Ticket
    {
        $ticket = $this->record($context, WorkflowRecordType::Ticket, $key);

        // Never false in practice — find() is the only thing that fills it and
        // it queries that model. Said out loud because the day a record type
        // returns something else, this is where it should stop.
        if (! $ticket instanceof Ticket) {
            throw new RuntimeException(__('workflows.errors.record_not_found', [
                'what' => WorkflowRecordType::Ticket->label(),
            ]));
        }

        return $ticket;
    }

    /** The contract a step names, or the one the trigger was about. */
    protected function contract(WorkflowStepContext $context, string $key = 'contract_id'): Contract
    {
        return $this->contractOfKind($context, WorkflowRecordType::Contract, $key);
    }

    /**
     * The template a step names, which is never the one the trigger brought.
     *
     * Nothing happens to a mould, so there is no trigger to fall back on: this
     * one has to be picked, and a step that forgot says so.
     */
    protected function contractTemplate(WorkflowStepContext $context, string $key = 'template_id'): Contract
    {
        return $this->contractOfKind($context, WorkflowRecordType::ContractTemplate, $key);
    }

    private function contractOfKind(WorkflowStepContext $context, WorkflowRecordType $type, string $key): Contract
    {
        $contract = $this->record($context, $type, $key);

        // Never false in practice — find() queries that model. Said out loud
        // because this is where it should stop if that ever stops being true.
        if (! $contract instanceof Contract) {
            throw new RuntimeException(__('workflows.errors.record_not_found', ['what' => $type->label()]));
        }

        return $contract;
    }

    /** The document a step names, or the one the trigger was about. */
    protected function document(WorkflowStepContext $context, string $key = 'document_id'): Document
    {
        $document = $this->record($context, WorkflowRecordType::Document, $key);

        if (! $document instanceof Document) {
            throw new RuntimeException(__('workflows.errors.record_not_found', [
                'what' => WorkflowRecordType::Document->label(),
            ]));
        }

        return $document;
    }

    /** The poll a step names, or the one the trigger was about. */
    protected function poll(WorkflowStepContext $context, string $key = 'poll_id'): Poll
    {
        $poll = $this->record($context, WorkflowRecordType::Poll, $key);

        if (! $poll instanceof Poll) {
            throw new RuntimeException(__('workflows.errors.record_not_found', [
                'what' => WorkflowRecordType::Poll->label(),
            ]));
        }

        return $poll;
    }

    /**
     * The person a step names, or the one the trigger was about.
     *
     * The same convention the record fields run on, applied to people: an empty
     * box means "wie dit in gang zette", which is what nearly every workflow
     * about a person means and needs no variable at all.
     */
    protected function memberOrTriggerUser(WorkflowStepContext $context, string $key = 'user_id'): User
    {
        if (filled($context->setting($key))) {
            return $this->member($context, $key);
        }

        $id = $context->value('trigger.user.id');

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_person_anywhere'));
        }

        return $this->memberNamed($context, (string) $id);
    }

    /** Somebody in this workspace. */
    protected function member(WorkflowStepContext $context, string $key = 'user_id'): User
    {
        $id = $context->setting($key);

        if (blank($id)) {
            throw new RuntimeException(__('workflows.errors.no_person_chosen'));
        }

        return $this->memberNamed($context, (string) $id);
    }

    /**
     * The person a value names: by id, or by the address they are known under.
     *
     * An address as well as an id for the same reason the channel takes a name.
     * A person field may hold a variable now, and what a trigger knows about
     * somebody is not always their id — a signer is an e-mail address and
     * nothing else until they turn out to have an account here. "Stuur de
     * aanvrager een bericht" has to be sayable with whatever the trigger
     * carried.
     *
     * Scoped to members() in both branches, and that scoping is the entire
     * safety of letting a variable in here: whatever the value resolves to, it
     * can only be somebody who is already in this workspace. A signer from
     * outside finds nobody and the step stops with a sentence on the run
     * screen, which is the correct outcome — a workflow is not a way to message
     * strangers.
     */
    private function memberNamed(WorkflowStepContext $context, string $named): User
    {
        $named = trim($named);
        $members = $context->workspace()->members();

        $member = ctype_digit($named)
            ? $members->whereKey($named)->first()
            // Case-insensitively, because an address is typed by a person and
            // arrives however they wrote it.
            : $members->whereRaw('lower(users.email) = ?', [mb_strtolower($named)])->first();

        if ($member === null) {
            throw new RuntimeException(__('workflows.errors.person_not_found'));
        }

        return $member;
    }

    /**
     * Whose rights this step runs with.
     *
     * The runner refuses an ownerless workflow before any step gets a turn, so
     * this being null would mean the run got past that check — worth saying out
     * loud rather than carrying on with a permission question nobody is
     * answering.
     */
    protected function actor(WorkflowStepContext $context): User
    {
        $actor = $context->actor();

        if ($actor === null) {
            throw new RuntimeException(__('workflows.errors.no_owner'));
        }

        return $actor;
    }

    /**
     * The name a workflow's messages appear under.
     *
     * Whatever the workflow says to sign them with, marked as a bot the way
     * every other automatic message in this application is — see
     * Workflow::botName() for why an empty box falls back to the workflow's own
     * name, and never to the owner's.
     */
    protected function botName(WorkflowStepContext $context): string
    {
        return $context->workflow->botName();
    }
}
