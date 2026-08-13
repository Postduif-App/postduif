<?php

use App\Enums\ChannelTicketPolicy;
use App\Enums\WorkflowRecordType;
use App\Enums\WorkflowRunStatus;
use App\Features\Tickets;
use App\Features\Timeclock;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowStepContext;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * An action pointed at a ticket, which is what the record field is for.
 *
 * Here rather than in the application because the first real one is a story
 * away — the contract and ticket actions both land on this. What it proves is
 * the machinery underneath: the picker, the validation and the lookup.
 */
class TouchingAction extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return 'Raak een ticket aan';
    }

    public static function description(): string
    {
        return 'Bestaat alleen in deze test.';
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [WorkflowField::record('ticket_id', WorkflowRecordType::Ticket, 'Ticket')];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $ticket = $this->ticket($context);

        return ['ticket' => ['id' => $ticket->id, 'number' => $ticket->number]];
    }
}

/** The register with the test action in it, in place of the real one. */
function withTouchingAction(): void
{
    $registry = app(WorkflowRegistry::class);
    $registry->registerAction(TouchingAction::class);
}

/**
 * A beheerder, a workflow of theirs, and a channel with a ticket in it.
 *
 * @return array{0: User, 1: Workspace, 2: Workflow, 3: Channel, 4: Ticket}
 */
function configurationScene(): array
{
    [$admin, $workspace] = workflowBeheerder();

    $channel = ticketChannel($workspace, $admin);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'opened_by' => $admin->id,
        'title' => 'Printer doet niets',
    ]);

    return [$admin, $workspace, $workflow, $channel, $ticket];
}

/**
 * A channel that keeps tickets, in a workspace that does too.
 *
 * Both halves matter for these tests: TicketPolicy::view leans on the channel,
 * and a channel that keeps no tickets shows none — which would make every
 * refusal here pass for the wrong reason.
 */
function ticketChannel(Workspace $workspace, User $member): Channel
{
    Feature::for($workspace)->activate(Tickets::class);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return $channel;
}

/**
 * One save, with whatever the caller wants to put wrong in it.
 *
 * @param  array<string, mixed>  $overrides
 */
function saveWorkflow(User $admin, Workflow $workflow, array $overrides = []): TestResponse
{
    return test()->actingAs($admin)->put(route('workflows.update', $workflow), [
        'name' => 'Storingsmelder',
        'trigger_type' => 'message-keyword',
        'trigger_config' => ['keywords' => ['storing']],
        'steps' => [],
        ...$overrides,
    ]);
}

it('refuses a trigger that is missing what it cannot do without', function () {
    [$admin, , $workflow] = configurationScene();

    saveWorkflow($admin, $workflow, ['trigger_config' => []])
        ->assertSessionHasErrors('trigger_config.keywords');
});

it('refuses a step with a required field left empty, before it can fail at three in the morning', function () {
    [$admin, , $workflow] = configurationScene();

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'send-channel-message',
        'config' => ['body' => 'Er is iets aan de hand'],
    ]]])->assertSessionHasErrors('steps.0.config.channel_id');
});

it('names the box the error belongs to, lanes and all', function () {
    [$admin, , $workflow, $channel] = configurationScene();

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'branch',
        'config' => [],
        'branches' => [
            'then' => [[
                'kind' => 'action',
                'action_type' => 'send-channel-message',
                'config' => ['channel_id' => $channel->id],
            ]],
            'else' => [],
        ],
    ]]])->assertSessionHasErrors('steps.0.branches.then.0.config.body');
});

it('refuses a channel from somebody else his workspace', function () {
    [$admin, , $workflow] = configurationScene();
    [$otherAdmin, $elsewhere] = workflowBeheerder();
    $theirs = channelWithMember($elsewhere, $otherAdmin);

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'send-channel-message',
        'config' => ['channel_id' => $theirs->id, 'body' => 'Hoi'],
    ]]])->assertSessionHasErrors('steps.0.config.channel_id');
});

/**
 * A channel named by its name is left to the run, on purpose: FindsTargets
 * looks those up while the workflow runs, and a channel renamed afterwards
 * would otherwise make an untouched workflow refuse to save.
 */
it('lets a channel named by name or by variable through', function () {
    [$admin, , $workflow, $channel] = configurationScene();

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'send-channel-message',
        'config' => ['channel_id' => "#{$channel->name}", 'body' => 'Hoi'],
    ]]])->assertSessionHasNoErrors();

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'send-channel-message',
        'config' => ['channel_id' => '{{ trigger.channel.name }}', 'body' => 'Hoi'],
    ]]])->assertSessionHasNoErrors();
});

/**
 * The one thing about a variable that can be decided when it is saved: whether
 * it was allowed there at all. What it will hold is nobody's business until the
 * run — but "{{ trigger.x }} minuten" was never going to be a number.
 */
it('refuses a variable in a field that cannot resolve one', function () {
    [$admin, , $workflow] = configurationScene();

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'delay',
        'config' => ['minutes' => '{{ trigger.answers.hoelang }}'],
    ]]])->assertSessionHasErrors('steps.0.config.minutes');
});

it('refuses a choice that is not on the list', function () {
    [$admin, $workspace, $workflow] = configurationScene();

    // The clock has to be on, or the request would refuse the trigger itself
    // and the choice inside it would never be looked at.
    Feature::for($workspace)->activate(Timeclock::class);

    saveWorkflow($admin, $workflow, [
        'trigger_type' => 'timeclock',
        'trigger_config' => ['direction' => 'sideways'],
    ])->assertSessionHasErrors('trigger_config.direction');
});

/*
 * The builder leaves these out of the picker, and a list that is only enforced
 * in the browser is not a list — a saved workflow pointed at a trigger this
 * workspace has switched off is one that waits forever.
 */
it('refuses a trigger this workspace has switched off', function () {
    [$admin, , $workflow] = configurationScene();

    saveWorkflow($admin, $workflow, [
        'trigger_type' => 'contract-signed',
        'trigger_config' => [],
    ])->assertSessionHasErrors('trigger_type');
});

it('refuses a record from another workspace, and keeps its own', function () {
    withTouchingAction();

    [$admin, , $workflow, , $ticket] = configurationScene();
    [$otherAdmin, $elsewhere] = workflowBeheerder();
    $theirChannel = ticketChannel($elsewhere, $otherAdmin);
    $theirs = Ticket::factory()->create([
        'workspace_id' => $elsewhere->id,
        'channel_id' => $theirChannel->id,
        'opened_by' => $otherAdmin->id,
    ]);

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'touching-action',
        'config' => ['ticket_id' => $theirs->id],
    ]]])->assertSessionHasErrors('steps.0.config.ticket_id');

    saveWorkflow($admin, $workflow, ['steps' => [[
        'kind' => 'action',
        'action_type' => 'touching-action',
        'config' => ['ticket_id' => $ticket->id],
    ]]])->assertSessionHasNoErrors();
});

/**
 * The commoner half of the record field, and the reason it is optional: an
 * empty box is not a half-written step, it is "the one the trigger brought".
 */
it('falls back to the record the trigger was about', function () {
    withTouchingAction();

    [, , $workflow, , $ticket] = configurationScene();

    $run = runStep($workflow, 'touching-action', [], [
        'trigger' => ['ticket' => ['id' => $ticket->id]],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.ticket.number'))->toBe($ticket->number);
});

it('lets a named record win over the one the trigger brought', function () {
    withTouchingAction();

    [$admin, $workspace, $workflow, $channel, $ticket] = configurationScene();

    $second = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'opened_by' => $admin->id,
    ]);

    $run = runStep($workflow, 'touching-action', ['ticket_id' => $second->id], [
        'trigger' => ['ticket' => ['id' => $ticket->id]],
    ]);

    expect(data_get($run->context, 'steps.0.ticket.id'))->toBe($second->id);
});

/**
 * The runner asks again, of the workflow's owner rather than of whoever saved
 * it. A record that has since moved out of reach — or a workflow whose
 * configuration was written straight into the database — stops here.
 */
it('refuses at run time what it would have refused at save time', function () {
    withTouchingAction();

    [, , $workflow] = configurationScene();
    [$otherAdmin, $elsewhere] = workflowBeheerder();
    $theirChannel = ticketChannel($elsewhere, $otherAdmin);
    $theirs = Ticket::factory()->create([
        'workspace_id' => $elsewhere->id,
        'channel_id' => $theirChannel->id,
        'opened_by' => $otherAdmin->id,
    ]);

    $run = runStep($workflow, 'touching-action', ['ticket_id' => $theirs->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('ticket');
});

it('says so when there is no record anywhere to act on', function () {
    withTouchingAction();

    [, , $workflow] = configurationScene();

    $run = runStep($workflow, 'touching-action', []);

    expect($run->status)->toBe(WorkflowRunStatus::Failed);
});

/** The picker offers this workspace's records, and only what it may show. */
it('offers the builder the records it can pick from', function () {
    [$admin, , $workflow, , $ticket] = configurationScene();

    $outsider = User::factory()->create();
    joinWorkspace($workflow->workspace, $outsider);

    test()->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where("records.ticket.{$ticket->id}", "#{$ticket->number} Printer doet niets"));

    // Somebody who is in the workspace but not in the channel is not shown
    // what the board holds — a dropdown is a poor place to learn that.
    Channel::query()->whereKey($ticket->channel_id)->firstOrFail()
        ->members()->attach($outsider->id, ['joined_at' => now()]);

    expect(WorkflowRecordType::Ticket->options($workflow->workspace, $outsider))
        ->toHaveKey((string) $ticket->id);
});
