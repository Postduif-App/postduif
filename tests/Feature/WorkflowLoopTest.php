<?php

use App\Actions\Workflows\ResumeWaitingWorkflows;
use App\Actions\Workflows\RunWorkflow;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepKind;
use App\Features\Tickets;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A step that walks a list.
 *
 * "Becommentarieer elk ticket dat over de einddatum is" was unwritable: the
 * action works on one row, the schedule trigger fires once, and there was
 * nothing in between. What is between them now is a loop, which lays its body
 * out once per row rather than running it — see RunWorkflow::unroll, and why
 * that is what lets a Delay sit inside one.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Workflow}
 */
function loopScene(): array
{
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

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'name' => 'Nachtploeg',
    ]);

    return [$member, $workspace, $channel, $workflow];
}

/** A ticket that is open and past its due date. */
function overdueTicket(Workspace $workspace, Channel $channel, User $member, string $title): Ticket
{
    return Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'opened_by' => $member->id,
        'title' => $title,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
        'due_at' => now()->subDay(),
    ]);
}

/** A loop over the overdue tickets with one step in its body. */
function loopWith(Workflow $workflow, string $action, array $config, string $source = 'overdue-tickets'): WorkflowStep
{
    $loop = WorkflowStep::factory()->for($workflow)->at(0)->create([
        'kind' => WorkflowStepKind::Loop,
        'action_type' => WorkflowStepKind::Loop->value,
        'config' => ['source' => $source],
    ]);

    WorkflowStep::factory()->for($workflow)->at(1)->doing($action, $config)->create([
        'parent_step_id' => $loop->id,
        'branch' => 'then',
    ]);

    return $loop;
}

function runLoop(Workflow $workflow): WorkflowRun
{
    $run = WorkflowRun::factory()->for($workflow)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    return $run->fresh();
}

it('runs its body once per row, with that row in {{ item }}', function () {
    [$member, $workspace, $channel, $workflow] = loopScene();

    overdueTicket($workspace, $channel, $member, 'Printer doet niets');
    overdueTicket($workspace, $channel, $member, 'Koffie is op');

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Nog open: {{ item.ticket.title }}',
    ]);

    $run = runLoop($workflow);

    $bodies = $channel->messages()->orderBy('id')->pluck('body');

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($bodies)->toHaveCount(2)
        // The row is spelled the way a trigger spells one — see RecordSnapshot,
        // which both read from.
        ->and($bodies[0])->toContain('Printer doet niets')
        ->and($bodies[1])->toContain('Koffie is op')
        // And how many there were, for a message after the loop to say out
        // loud: a loop body cannot count itself.
        ->and(data_get($run->context, 'steps.0.count'))->toBe(2)
        // Put back to nothing on the way out, so nothing after the loop reads
        // the last row it happened to walk as though it were still the subject.
        ->and(data_get($run->context, 'item'))->toBeNull();
});

it('numbers the rows it walks', function () {
    [$member, $workspace, $channel, $workflow] = loopScene();

    overdueTicket($workspace, $channel, $member, 'Eerste');
    overdueTicket($workspace, $channel, $member, 'Tweede');

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Rij {{ item.index }}',
    ]);

    runLoop($workflow);

    expect($channel->messages()->orderBy('id')->pluck('body')->all())
        ->toBe(['Rij 1', 'Rij 2']);
});

it('does nothing at all, successfully, when the list is empty', function () {
    [, , $channel, $workflow] = loopScene();

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Nog open: {{ item.ticket.title }}',
    ]);

    $run = runLoop($workflow);

    // A workflow that ran on a quiet Tuesday and found nothing to do is not a
    // workflow that went wrong.
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($channel->messages()->count())->toBe(0)
        ->and(data_get($run->context, 'steps.0.count'))->toBe(0);
});

it('walks only this workspace, whatever is overdue elsewhere', function () {
    [$member, $workspace, $channel, $workflow] = loopScene();

    overdueTicket($workspace, $channel, $member, 'Van ons');

    $stranger = User::factory()->create();
    $elsewhere = workspaceWithMember($stranger, SystemRole::Admin);
    Feature::for($elsewhere)->activate(Tickets::class);
    $theirChannel = Channel::factory()->create([
        'workspace_id' => $elsewhere->id,
        'created_by' => $stranger->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);
    overdueTicket($elsewhere, $theirChannel, $stranger, 'Van hen');

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => '{{ item.ticket.title }}',
    ]);

    $run = runLoop($workflow);

    /*
     * A loop is the one place where "one row too many" is not something anybody
     * notices — it is fifty messages about another company's tickets.
     */
    expect(data_get($run->context, 'steps.0.count'))->toBe(1)
        ->and($channel->messages()->sole()->body)->toContain('Van ons');
});

it('stops when the workspace has that part switched off', function () {
    [, $workspace, $channel, $workflow] = loopScene();

    Feature::for($workspace)->deactivate(Tickets::class);

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => '{{ item.ticket.title }}',
    ]);

    $run = runLoop($workflow);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('staat uit');
});

it('refuses a loop with no list to walk', function () {
    [, , $channel, $workflow] = loopScene();

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'x',
    ], source: '');

    $run = runLoop($workflow);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('lijst');
});

it('survives a wait inside its body and comes back on the right row', function () {
    [$member, $workspace, $channel, $workflow] = loopScene();

    overdueTicket($workspace, $channel, $member, 'Eerste');
    overdueTicket($workspace, $channel, $member, 'Tweede');

    $loop = WorkflowStep::factory()->for($workflow)->at(0)->create([
        'kind' => WorkflowStepKind::Loop,
        'action_type' => WorkflowStepKind::Loop->value,
        'config' => ['source' => 'overdue-tickets'],
    ]);

    WorkflowStep::factory()->for($workflow)->at(1)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Over {{ item.ticket.title }}',
    ])->create(['parent_step_id' => $loop->id, 'branch' => 'then']);

    WorkflowStep::factory()->for($workflow)->at(2)->doing('delay', ['minutes' => 60])
        ->create(['parent_step_id' => $loop->id, 'branch' => 'then']);

    $run = runLoop($workflow);

    // One message, then the run is put down in the middle of the first row.
    expect($run->status)->toBe(WorkflowRunStatus::Waiting)
        ->and($channel->messages()->count())->toBe(1)
        // What is left is the rest of the loop, markers and all: the row it has
        // to pick up on is written down rather than worked out again.
        ->and($run->resume_plan)->not->toBeEmpty();

    $this->travel(2)->hours();
    app(ResumeWaitingWorkflows::class)->handle();
    app(RunWorkflow::class)->handle($run->fresh());

    // And again for the second row's wait.
    $this->travel(2)->hours();
    app(ResumeWaitingWorkflows::class)->handle();
    app(RunWorkflow::class)->handle($run->fresh());

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($channel->messages()->orderBy('id')->pluck('body')->all())
        ->toBe(['Over Eerste', 'Over Tweede']);
});

it('walks no more rows than it promised on the screen', function () {
    [$member, $workspace, $channel, $workflow] = loopScene();

    foreach (range(1, 55) as $number) {
        overdueTicket($workspace, $channel, $member, "Ticket {$number}");
    }

    loopWith($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => '{{ item.ticket.title }}',
    ]);

    $run = runLoop($workflow);

    /*
     * Not a performance number — it is the blast radius. Somebody who wrote
     * "de handvol die over tijd is" should not find out on the day it is four
     * hundred.
     */
    expect(data_get($run->context, 'steps.0.count'))->toBe(50)
        ->and($channel->messages()->count())->toBe(50);
});
