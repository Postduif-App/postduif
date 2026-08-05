<?php

use App\Enums\ChannelTicketPolicy;
use App\Enums\TicketPriority;
use App\Enums\WorkflowRunStatus;
use App\Features\Tickets;
use App\Models\Ticket;
use Laravel\Pennant\Feature;

/**
 * Opening a ticket from a workflow.
 *
 * The fixtures come from WorkflowActionTest — workflowWithChannel() and
 * runStep() — because this is one more action rather than a world of its own.
 *
 * Two switches rather than one, and the second is easy to forget: the workspace
 * has to offer tickets at all, and the channel has to keep a board. A channel
 * starts with its board off, so a workflow pointed at an ordinary channel opens
 * nothing — which is a test of its own below.
 */
function ticketWorkflow(): array
{
    [$workflow, $workspace, $owner, $channel] = workflowWithChannel();

    Feature::for($workspace)->activate(Tickets::class);

    $channel->forceFill(['ticket_policy' => ChannelTicketPolicy::Everyone])->save();

    return [$workflow, $workspace, $owner, $channel];
}

it('puts work on the board of the channel it was pointed at', function () {
    [$workflow, , $owner, $channel] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
        'body' => 'Gemeld in het kanaal.',
    ]);

    $ticket = Ticket::sole();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($ticket->title)->toBe('De printer doet het niet')
        ->and($ticket->body)->toBe('Gemeld in het kanaal.')
        ->and($ticket->channel_id)->toBe($channel->id)
        // In the name of whoever wrote the workflow: a ticket has an owner in a
        // way a message does not.
        ->and($ticket->opened_by)->toBe($owner->id)
        ->and($ticket->priority)->toBe(TicketPriority::Normal);
});

it('hands the ticket on to the steps after it', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
    ]);

    $ticket = Ticket::sole();

    expect(data_get($run->context, 'steps.0.ticket.id'))->toBe($ticket->id)
        ->and(data_get($run->context, 'steps.0.ticket.number'))->toBe($ticket->number)
        ->and(data_get($run->context, 'steps.0.channel.id'))->toBe($channel->id);
});

it('writes what the trigger saw into the ticket', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'Storing gemeld door {{ trigger.user.name }}',
        'body' => '{{ trigger.message.text }}',
    ], ['trigger' => [
        'user' => ['name' => 'Pietje'],
        'message' => ['text' => 'De printer doet het niet'],
    ]]);

    $ticket = Ticket::sole();

    expect($ticket->title)->toBe('Storing gemeld door Pietje')
        ->and($ticket->body)->toBe('De printer doet het niet');
});

it('takes the urgency it was given', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer staat in brand',
        'priority' => TicketPriority::Urgent->value,
    ]);

    expect(Ticket::sole()->priority)->toBe(TicketPriority::Urgent);
});

it('falls back to normal for an urgency it does not recognise', function (mixed $priority) {
    [$workflow, , , $channel] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
        'priority' => $priority,
    ]);

    // A word nobody recognises is not a reason to fail a run that is otherwise
    // fine — the ticket still gets opened.
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(Ticket::sole()->priority)->toBe(TicketPriority::Normal);
})->with([
    'left alone' => [null],
    'a word from a version that no longer exists' => ['vreselijk'],
]);

it('opens one with a title and nothing else', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
        'body' => '',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(Ticket::sole()->body)->toBe('');
});

it('refuses to open one with nothing to call it', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        // Everything the title was made of turned out to be missing.
        'title' => '{{ trigger.message.text }}',
        'body' => 'Iets',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Ticket::count())->toBe(0);
});

it('opens nothing where the workspace stopped keeping tickets', function () {
    [$workflow, $workspace, , $channel] = ticketWorkflow();

    Feature::for($workspace)->deactivate(Tickets::class);

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Ticket::count())->toBe(0);
});

it('opens nothing in a channel that keeps no board', function () {
    [$workflow, , , $channel] = ticketWorkflow();

    $channel->forceFill(['ticket_policy' => ChannelTicketPolicy::Disabled])->save();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Ticket::count())->toBe(0);
});

it('opens nothing in a channel the owner was taken out of', function () {
    [$workflow, , $owner, $channel] = ticketWorkflow();

    $channel->members()->detach($owner->id);

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Ticket::count())->toBe(0);
});

it('opens nothing in a channel from another workspace', function () {
    [$workflow] = ticketWorkflow();

    [, , $stranger, $elsewhere] = ticketWorkflow();

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $elsewhere->id,
        'title' => 'De printer doet het niet',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Ticket::count())->toBe(0)
        ->and($stranger)->not->toBeNull();
});

it('says so on the run screen rather than failing silently', function () {
    [$workflow, $workspace, , $channel] = ticketWorkflow();

    Feature::for($workspace)->deactivate(Tickets::class);

    $run = runStep($workflow, 'create-ticket', [
        'channel_id' => $channel->id,
        'title' => 'De printer doet het niet',
    ]);

    expect($run->failure_reason)->toBe(__('workflows.errors.tickets_off'));
});
