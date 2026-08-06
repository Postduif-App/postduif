<?php

use App\Enums\SystemRole;
use App\Enums\WorkflowStepKind;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\ChannelLink;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The other kind of button in the bar above a channel: one that starts a
 * workflow rather than opening a URL.
 */

/** A workflow on the button trigger, switched on, with one step to run. */
function buttonWorkflow(Workspace $workspace, User $owner): Workflow
{
    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Storingsdienst bellen',
        'trigger_type' => 'button',
        'enabled_at' => now(),
    ]);

    WorkflowStep::factory()->create([
        'workflow_id' => $workflow->id,
        'kind' => WorkflowStepKind::Action,
        'action_type' => 'send-channel-message',
        'config' => ['channel_id' => $workflow->workspace->channels()->value('id'), 'body' => 'Onderweg.'],
        'position' => 0,
    ]);

    return $workflow;
}

it('adds a button that starts a workflow, with no address on it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Storing melden',
        'workflow_id' => $workflow->id,
    ])->assertRedirect();

    $added = $channel->links()->sole();

    expect($added->workflow_id)->toBe($workflow->id)
        ->and($added->url)->toBeNull()
        ->and($added->startsWorkflow())->toBeTrue();
});

it('refuses a button that would both open an address and start a workflow', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Allebei',
        'url' => 'https://example.com',
        'workflow_id' => $workflow->id,
    ])->assertSessionHasErrors('url');

    expect($channel->links()->count())->toBe(0);
});

it('refuses a button pointing at nothing at all', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Leeg',
    ])->assertSessionHasErrors('url');

    expect($channel->links()->count())->toBe(0);
});

it('refuses a workflow that is not on the button trigger', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    Feature::for($workspace)->activate(WorkflowsFeature::class);

    // A keyword workflow started from a button would be handed none of the
    // things it reads, and fail on its first step.
    $keyword = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
        'trigger_type' => 'message-keyword',
        'enabled_at' => now(),
    ]);

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Verkeerd',
        'workflow_id' => $keyword->id,
    ])->assertSessionHasErrors('workflow_id');
});

it('refuses a workflow from another workspace', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    [$otherCreator, , $otherWorkspace] = settingsFixture();
    $theirs = buttonWorkflow($otherWorkspace, $otherCreator);

    actingAs($creator)->post(route('chat.channels.links.store', [$workspace, $channel]), [
        'label' => 'Van iemand anders',
        'workflow_id' => $theirs->id,
    ])->assertSessionHasErrors('workflow_id');
});

it('starts the workflow when the button is pressed', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);
    $link = ChannelLink::factory()->create([
        'channel_id' => $channel->id,
        'workflow_id' => $workflow->id,
        'url' => null,
    ]);

    actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->post(route('chat.channels.links.run', [$workspace, $channel, $link]))
        ->assertRedirect(route('chat.show', [$workspace, $channel]));

    $run = WorkflowRun::where('workflow_id', $workflow->id)->sole();

    // What the trigger promised: the channel it hangs above, and whoever
    // pressed it. No author beside them — nothing was pointed at.
    expect($run->context['trigger']['channel']['id'])->toBe($channel->id)
        ->and($run->context['trigger']['user']['id'])->toBe($member->id)
        ->and($run->context['trigger'])->not->toHaveKey('author');
});

it('is a 404 for a button that only opens an address', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    Feature::for($workspace)->activate(WorkflowsFeature::class);
    $link = ChannelLink::factory()->create(['channel_id' => $channel->id]);

    actingAs($creator)
        ->post(route('chat.channels.links.run', [$workspace, $channel, $link]))
        ->assertNotFound();
});

it('is a 404 once the workflow behind the button is switched off', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);
    // Through the model rather than update(): enabled_at is deliberately not
    // fillable, so an update() here would quietly do nothing.
    $workflow->disable();

    $link = ChannelLink::factory()->create([
        'channel_id' => $channel->id,
        'workflow_id' => $workflow->id,
        'url' => null,
    ]);

    actingAs($creator)
        ->post(route('chat.channels.links.run', [$workspace, $channel, $link]))
        ->assertNotFound();

    expect(WorkflowRun::count())->toBe(0);
});

it('refuses somebody who cannot see the channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);
    $link = ChannelLink::factory()->create([
        'channel_id' => $channel->id,
        'workflow_id' => $workflow->id,
        'url' => null,
    ]);

    $channel->update(['type' => 'private']);
    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    actingAs($outsider)
        ->post(route('chat.channels.links.run', [$workspace, $channel, $link]))
        ->assertForbidden();
});

it('takes the button with it when the workflow is deleted', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $workflow = buttonWorkflow($workspace, $creator);
    ChannelLink::factory()->create([
        'channel_id' => $channel->id,
        'workflow_id' => $workflow->id,
        'url' => null,
    ]);

    $workflow->delete();

    // A button whose workflow is gone does nothing at all; leaving it there is
    // leaving a label people press twice before deciding the chat is broken.
    expect($channel->links()->count())->toBe(0);
});

it('offers the workflows a button may start, only to whoever configures the channel', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    buttonWorkflow($workspace, $creator);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.buttonWorkflows.0.name', 'Storingsdienst bellen'));

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.buttonWorkflows', []));
});
