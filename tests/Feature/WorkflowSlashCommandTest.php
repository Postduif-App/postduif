<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkflowStepKind;
use App\Features\Workflows;
use App\Models\EphemeralNotice;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\Triggers\SlashCommandTrigger;
use App\Workflows\WorkflowRegistry;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * The command a workflow answers to: how it is tidied up on the way in, and
 * which names it is not allowed to have.
 *
 * The typing itself — offering the command in the composer and firing it — is
 * the next piece of work; what is pinned down here is the name.
 */

/**
 * Saving a workflow whole, with the command in its trigger config.
 *
 * The whole thing every time, because that is what the builder sends: there is
 * no endpoint that saves a trigger on its own — see WorkflowController::update.
 */
function saveSlashCommand(Workflow $workflow, string $command): TestResponse
{
    return test()->actingAs($workflow->owner)
        ->put(route('workflows.update', $workflow), [
            'name' => $workflow->name,
            'trigger_type' => 'slash-command',
            'trigger_config' => ['command' => $command],
            'steps' => [],
        ]);
}

it('is registered among the triggers a workflow may use', function () {
    expect(app(WorkflowRegistry::class)->triggers())
        ->toHaveKey('slash-command')
        ->and(SlashCommandTrigger::key())->toBe('slash-command');
});

it('offers what the person typed to the steps below it', function () {
    expect(array_keys(SlashCommandTrigger::provides()))
        ->toContain('command', 'arguments', 'channel.id', 'user.id');
});

it('stores a command without its slash, in lowercase', function () {
    [$admin, $workspace] = workflowBeheerder();
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($workflow, ' /Storing-Melden ')->assertRedirect();

    expect($workflow->fresh()->triggerSetting('command'))->toBe('storing-melden');
});

it('refuses a command with a space in it, because everything after one is the arguments', function () {
    [$admin, $workspace] = workflowBeheerder();
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($workflow, 'storing melden')
        ->assertSessionHasErrors('trigger_config.command');

    expect($workflow->fresh()->triggerSetting('command'))->toBeNull();
});

it('refuses a command the message field already answers to itself', function () {
    [$admin, $workspace] = workflowBeheerder();
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($workflow, '/poll')
        ->assertSessionHasErrors('trigger_config.command');
});

it('refuses a command another workflow in the workspace already has', function () {
    [$admin, $workspace] = workflowBeheerder();

    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'trigger_type' => 'slash-command',
        'trigger_config' => ['command' => 'storing'],
    ]);

    $second = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($second, 'Storing')
        ->assertSessionHasErrors('trigger_config.command');
});

it('lets a workflow keep its own command when it is saved again', function () {
    [$admin, $workspace] = workflowBeheerder();
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'trigger_type' => 'slash-command',
        'trigger_config' => ['command' => 'storing'],
    ]);

    saveSlashCommand($workflow, 'storing')->assertRedirect();

    expect($workflow->fresh()->triggerSetting('command'))->toBe('storing');
});

it('leaves a command free once the workflow that had it moved to another trigger', function () {
    [$admin, $workspace] = workflowBeheerder();

    // Was a slash command; the config is still there, the column says otherwise.
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'trigger_type' => 'message-keyword',
        'trigger_config' => ['command' => 'storing', 'keywords' => ['storing']],
    ]);

    $second = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($second, 'storing')->assertRedirect();

    expect($second->fresh()->triggerSetting('command'))->toBe('storing');
});

it('leaves the same command in another workspace alone', function () {
    [$admin, $workspace] = workflowBeheerder();
    [$otherAdmin, $otherWorkspace] = workflowBeheerder();

    Workflow::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'created_by' => $otherAdmin->id,
        'trigger_type' => 'slash-command',
        'trigger_config' => ['command' => 'storing'],
    ]);

    $mine = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    saveSlashCommand($mine, 'storing')->assertRedirect();

    expect($mine->fresh()->triggerSetting('command'))->toBe('storing');
});

it('does not touch the config of a trigger that is not a command', function () {
    [$admin, $workspace] = workflowBeheerder();
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    test()->actingAs($admin)->put(route('workflows.update', $workflow), [
        'name' => $workflow->name,
        'trigger_type' => 'message-keyword',
        'trigger_config' => ['keywords' => ['Storing'], 'command' => '/Niet Genormaliseerd'],
        'steps' => [],
    ])->assertRedirect();

    expect($workflow->fresh()->triggerSetting('command'))->toBe('/Niet Genormaliseerd');
});

/** A workflow that answers to a command, switched on, with one step. */
function commandWorkflow(Workspace $workspace, User $owner, string $command): Workflow
{
    Feature::for($workspace)->activate(Workflows::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Storingsdienst',
        'trigger_type' => 'slash-command',
        'trigger_config' => ['command' => $command],
        'enabled_at' => now(),
    ]);

    WorkflowStep::factory()->create([
        'workflow_id' => $workflow->id,
        'kind' => WorkflowStepKind::Action,
        'action_type' => 'send-channel-message',
        'config' => ['channel_id' => $workspace->channels()->value('id'), 'body' => 'Onderweg.'],
        'position' => 0,
    ]);

    return $workflow;
}

it('starts the workflow behind a command and says so to the one who typed it', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $workflow = commandWorkflow($workspace, $member, 'storing');

    test()->actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->post(route('chat.commands.store', [$workspace, $channel]), [
            'command' => 'storing',
            'arguments' => 'de printer doet het niet',
        ])->assertRedirect();

    $run = WorkflowRun::where('workflow_id', $workflow->id)->sole();

    expect($run->context['trigger']['arguments'])->toBe('de printer doet het niet')
        ->and($run->context['trigger']['user']['id'])->toBe($member->id);

    // The receipt: this member's, in this channel, and nobody else's.
    $notice = EphemeralNotice::sole();

    expect($notice->user_id)->toBe($member->id)
        ->and($notice->channel_id)->toBe($channel->id)
        ->and($notice->body)->toContain('Storingsdienst')
        // Something that went to plan is worth ten minutes, not forever.
        ->and($notice->expires_at)->not->toBeNull();
});

it('says so, to that one person, when no workflow answers to the command', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    Feature::for($workspace)->activate(Workflows::class);

    test()->actingAs($member)
        ->post(route('chat.commands.store', [$workspace, $channel]), [
            'command' => 'bestaatniet',
        ])->assertRedirect();

    expect(WorkflowRun::count())->toBe(0)
        ->and(EphemeralNotice::sole())
        ->body->toContain('bestaatniet')
        // A receipt for something that did not happen waits to be read.
        ->expires_at->toBeNull();
});

it('reads the command however it was typed', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    commandWorkflow($workspace, $member, 'storing');

    test()->actingAs($member)
        ->post(route('chat.commands.store', [$workspace, $channel]), [
            'command' => '/Storing',
        ])->assertRedirect();

    expect(WorkflowRun::count())->toBe(1);
});

it('leaves a command from another workspace alone', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    [$otherMember, , $otherWorkspace] = ticketFixture();
    commandWorkflow($otherWorkspace, $otherMember, 'storing');
    Feature::for($workspace)->activate(Workflows::class);

    test()->actingAs($member)
        ->post(route('chat.commands.store', [$workspace, $channel]), [
            'command' => 'storing',
        ])->assertRedirect();

    expect(WorkflowRun::count())->toBe(0);
});

it('refuses somebody who may not post in the channel', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    commandWorkflow($workspace, $member, 'storing');

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);
    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    test()->actingAs($outsider)
        ->post(route('chat.commands.store', [$workspace, $channel]), [
            'command' => 'storing',
        ])->assertForbidden();

    expect(WorkflowRun::count())->toBe(0);
});

it('offers a channel his commands to whoever may post there', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    commandWorkflow($workspace, $member, 'storing');

    test()->actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.commands.0.name', 'storing'));
});
