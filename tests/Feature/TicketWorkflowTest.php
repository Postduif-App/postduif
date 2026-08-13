<?php

use App\Actions\Tickets\CommentOnTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Console\Commands\NotifyStaleTickets;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WorkflowRunStatus;
use App\Features\Tickets;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\Triggers\TicketChangedTrigger;
use App\Workflows\Triggers\TicketCommentedTrigger;
use App\Workflows\Triggers\TicketCreatedTrigger;
use App\Workflows\Triggers\TicketStaleTrigger;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

/**
 * A workspace with a ticket board and workflows, and a ticket on it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Ticket}
 */
function ticketWorkflowScene(): array
{
    Notification::fake();

    $member = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($member, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Tickets::class);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
        'title' => 'Printer doet niets',
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    return [$member, $workspace, $channel, $ticket];
}

/**
 * A switched-on workflow waiting for one ticket moment, with one harmless step.
 *
 * Not called ticketWorkflow: WorkflowTicketTest already has a function by that
 * name, and two test files in one suite share one global namespace — a
 * collision there is a fatal error before a single test runs.
 */
function watchingWorkflow(User $owner, Channel $channel, string $trigger, array $config = []): Workflow
{
    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
        'name' => 'Ticketdienst',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $channel->id,
    ])->create();

    return $workflow;
}

function ticketRunsOf(Workflow $workflow): int
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->count();
}

function lastTicketRun(Workflow $workflow): ?WorkflowRun
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->latest('id')->first();
}

it('is called what a workflow stores', function () {
    expect(TicketCreatedTrigger::key())->toBe('ticket-created')
        ->and(TicketChangedTrigger::key())->toBe('ticket-changed')
        ->and(TicketCommentedTrigger::key())->toBe('ticket-commented')
        ->and(TicketStaleTrigger::key())->toBe('ticket-stale');
});

it('starts a workflow when a ticket comes in, with everything it knows about it', function () {
    [$member, , $channel] = ticketWorkflowScene();
    $workflow = watchingWorkflow($member, $channel, TicketCreatedTrigger::key());

    app(CreateTicket::class)->handle($channel, $member, 'Deur klemt', 'Al twee dagen');

    $run = lastTicketRun($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.ticket.title'))->toBe('Deur klemt')
        ->and(data_get($run->context, 'trigger.ticket.status'))->toBe('open')
        ->and(data_get($run->context, 'trigger.ticket.has_assignee'))->toBeFalse()
        ->and(data_get($run->context, 'trigger.ticket.answered'))->toBeFalse()
        ->and(data_get($run->context, 'trigger.reporter.name'))->toBe('Sanne')
        ->and(data_get($run->context, 'trigger.actor.name'))->toBe('Sanne')
        ->and(data_get($run->context, 'trigger.channel.id'))->toBe($channel->id);
});

/**
 * The from-and-to is the whole reason TicketChanged exists beside the broadcast:
 * "ging hij naar afgerond" cannot be answered by "ticket 12 is anders dan het
 * was".
 */
it('says what a change moved from and to', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();
    $workflow = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'status']);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Resolved, $member);

    $run = lastTicketRun($workflow);

    expect(data_get($run->context, 'trigger.change.kind'))->toBe('status')
        ->and(data_get($run->context, 'trigger.change.from'))->toBe('open')
        ->and(data_get($run->context, 'trigger.change.to'))->toBe('resolved')
        ->and(data_get($run->context, 'trigger.ticket.status'))->toBe('resolved');
});

it('only runs for the kind of change it was written for', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $onStatus = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'status']);
    $onPriority = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'priority']);
    $onAnything = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'any']);

    app(UpdateTicket::class)->priority($ticket, TicketPriority::Urgent, $member);

    expect(ticketRunsOf($onStatus))->toBe(0)
        ->and(ticketRunsOf($onPriority))->toBe(1)
        ->and(ticketRunsOf($onAnything))->toBe(1);
});

/**
 * Picking up an open ticket also moves it to in behandeling — see UpdateTicket
 * — so this is two changes and two runs. Worth pinning down: a workflow that
 * fires twice for one click is exactly the sort of thing somebody finds out
 * about at three in the morning.
 */
it('reports an assignment and the status move it drags along', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();

    $colleague = User::factory()->create(['name' => 'Joris']);
    joinWorkspace($workspace, $colleague);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    $workflow = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'any']);

    app(UpdateTicket::class)->assign($ticket, $colleague, $member);

    expect(ticketRunsOf($workflow))->toBe(2)
        ->and(data_get(lastTicketRun($workflow)->context, 'trigger.ticket.has_assignee'))->toBeTrue()
        ->and(data_get(lastTicketRun($workflow)->context, 'trigger.assignee.name'))->toBe('Joris');
});

it('knows whether a comment was the answer the ticket had been waiting for', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();

    $colleague = User::factory()->create(['name' => 'Joris']);
    joinWorkspace($workspace, $colleague);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    $workflow = watchingWorkflow($member, $channel, TicketCommentedTrigger::key());

    app(CommentOnTicket::class)->handle($ticket, $colleague, 'We kijken ernaar.');

    expect(data_get(lastTicketRun($workflow)->context, 'trigger.comment.is_first_response'))->toBeTrue()
        ->and(data_get(lastTicketRun($workflow)->context, 'trigger.comment.body'))->toBe('We kijken ernaar.')
        ->and(data_get(lastTicketRun($workflow)->context, 'trigger.author.name'))->toBe('Joris');

    app(CommentOnTicket::class)->handle($ticket->refresh(), $colleague, 'Nog steeds mee bezig.');

    expect(data_get(lastTicketRun($workflow)->context, 'trigger.comment.is_first_response'))->toBeFalse();
});

it('runs on the nightly sweep, and says which kind of neglect it was', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $overdueOnly = watchingWorkflow($member, $channel, TicketStaleTrigger::key(), ['reason' => 'overdue']);
    $unansweredOnly = watchingWorkflow($member, $channel, TicketStaleTrigger::key(), ['reason' => 'unanswered']);

    // Never answered, and old enough for the sweep to notice.
    $ticket->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan(NotifyStaleTickets::class)->assertSuccessful();

    expect(ticketRunsOf($overdueOnly))->toBe(0)
        ->and(ticketRunsOf($unansweredOnly))->toBe(1)
        ->and(data_get(lastTicketRun($unansweredOnly)->context, 'trigger.stale.reason'))->toBe('unanswered')
        ->and(data_get(lastTicketRun($unansweredOnly)->context, 'trigger.ticket.hours_open'))->toBeGreaterThan(24);
});

it('leaves alone the workflows written about another channel', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();

    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    $here = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), [
        'kind' => 'any', 'channel_id' => $channel->id,
    ]);
    $there = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), [
        'kind' => 'any', 'channel_id' => $elsewhere->id,
    ]);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Closed, $member);

    expect(ticketRunsOf($here))->toBe(1)
        ->and(ticketRunsOf($there))->toBe(0);
});

it('stays out of a workspace that has switched tickets off', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();
    $workflow = watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'any']);

    Feature::for($workspace)->deactivate(Tickets::class);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Closed, $member);

    expect(ticketRunsOf($workflow))->toBe(0);
});

/** The board's own status moves still work; a workflow is not in the way. */
it('does not disturb what a change already did', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();
    watchingWorkflow($member, $channel, TicketChangedTrigger::key(), ['kind' => 'any']);

    app(UpdateTicket::class)->status($ticket, TicketStatus::Closed, $member);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->fresh()->closed_at)->not->toBeNull()
        ->and($ticket->events()->count())->toBe(1);
});

/*
 * The actions.
 */

it('updates status, priority and deadline in one step', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'update-ticket', [
        'status' => 'in_progress',
        'priority' => 'urgent',
        'due_in_days' => 2,
    ], ['trigger' => ['ticket' => ['id' => $ticket->id]]]);

    $ticket->refresh();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->due_at?->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and(data_get($run->context, 'steps.0.ticket.status'))->toBe('in_progress');
});

it('refuses a step that would change nothing', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'update-ticket', ['ticket_id' => $ticket->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed);
});

it('assigns a ticket, and takes it off again when nobody is named', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();

    $colleague = User::factory()->create(['name' => 'Joris']);
    joinWorkspace($workspace, $colleague);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'assign-ticket', [
        'ticket_id' => $ticket->id,
        'user_id' => $colleague->id,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($ticket->fresh()->assigned_to)->toBe($colleague->id)
        ->and(data_get($run->context, 'steps.0.assignee.name'))->toBe('Joris');

    $off = runStep($workflow, 'assign-ticket', ['ticket_id' => $ticket->id]);

    expect($off->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($ticket->fresh()->assigned_to)->toBeNull();
});

/**
 * Handing work to somebody who cannot open it is worse than not handing it over
 * at all: they are told they have it and find nothing there.
 */
it('will not hand a ticket to somebody who cannot see it', function () {
    [$member, $workspace, $channel, $ticket] = ticketWorkflowScene();

    /*
     * A guest who is not in this channel. An ordinary member would see the
     * ticket — the channel is public, and that is the point of a public channel
     * — so the case worth guarding is the one where the picker offers somebody
     * the board is genuinely closed to.
     */
    $outsider = User::factory()->create(['name' => 'Iemand anders']);
    joinWorkspace($workspace, $outsider, SystemRole::Guest);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'assign-ticket', [
        'ticket_id' => $ticket->id,
        'user_id' => $outsider->id,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($ticket->fresh()->assigned_to)->toBeNull();
});

it('puts a comment on a ticket, in the name of the workflow his owner', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'comment-on-ticket', [
        'ticket_id' => $ticket->id,
        'body' => 'Ontvangen, we kijken ernaar. ({{ trigger.ticket.number }})',
    ], ['trigger' => ['ticket' => ['number' => 7]]]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($ticket->comments()->first()->body)->toBe('Ontvangen, we kijken ernaar. (7)')
        ->and($ticket->comments()->first()->user_id)->toBe($member->id);
});

it('says so rather than putting a blank line on a ticket', function () {
    [$member, , $channel, $ticket] = ticketWorkflowScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $member->id,
    ]);

    $run = runStep($workflow, 'comment-on-ticket', [
        'ticket_id' => $ticket->id,
        'body' => '{{ trigger.answers.leeg }}',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($ticket->comments()->count())->toBe(0);
});

it('reads a ticket again after a wait, so a condition compares against today', function () {
    [$member, $workspace, , $ticket] = ticketWorkflowScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    // The deadline had not passed when the workflow was set off.
    $ticket->forceFill(['due_at' => now()->addHour()])->save();

    $before = ['trigger' => ['ticket' => ['id' => $ticket->id, 'is_overdue' => false]]];

    $this->travel(2)->hours();

    $run = runStep($workflow, 'read-ticket', ['ticket_id' => $ticket->id], $before);

    /*
     * This is the whole story: is_overdue is worked out against now(), so the
     * step says yes where the trigger's copy still says no — and it says it
     * under a path spelled the same way, so the condition after it reads the
     * way anybody would expect.
     */
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.ticket.is_overdue'))->toBeTrue()
        ->and(data_get($run->context, 'trigger.ticket.is_overdue'))->toBeFalse();
});
