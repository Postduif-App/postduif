<?php

use App\Enums\WorkflowRunStatus;
use App\Features\SharedChannels;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A workflow of the host's, in a workspace that shares channels, plus a second
 * workspace that accepts them.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Workspace, 4: Workflow}
 */
function shareWorkflowScene(): array
{
    [$host, $hostWorkspace, $channel, , $guestWorkspace] = sharedChannelFixture();

    Feature::for($hostWorkspace)->activate(WorkflowsFeature::class);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $hostWorkspace->id,
        'created_by' => $host->id,
        'name' => 'Partnermelder',
    ]);

    return [$host, $hostWorkspace, $channel, $guestWorkspace, $workflow];
}

it('offers a channel to the workspace a slug names, and grants nothing yet', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => $guestWorkspace->slug,
    ]);

    $share = ChannelShare::query()->sole();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        // An invitation and nothing more: the other side still has to say yes,
        // which is the whole difference between this and letting somebody in.
        ->and($share->isPending())->toBeTrue()
        ->and($share->isLive())->toBeFalse()
        ->and($share->can_post)->toBeTrue()
        ->and(data_get($run->context, 'steps.0.guest.name'))->toBe($guestWorkspace->name);
});

it('takes the slug out of a variable, and does not mind how it was typed', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => '{{ trigger.partner }}',
    ], ['trigger' => ['partner' => strtoupper($guestWorkspace->slug)]]);

    // A slug arriving in capitals is the same organisation to everybody except
    // a database.
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(ChannelShare::query()->sole()->workspace_id)->toBe($guestWorkspace->id);
});

it('can offer a channel to read but not to write in', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => $guestWorkspace->slug,
        'can_post' => 'no',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(ChannelShare::query()->sole()->can_post)->toBeFalse();
});

it('says which workspace it could not find rather than failing blankly', function () {
    [, , $channel, , $workflow] = shareWorkflowScene();

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => 'bestaat-niet',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('bestaat-niet')
        ->and(ChannelShare::query()->count())->toBe(0);
});

it('will not offer on a channel this workspace merely reaches into', function () {
    [, , , $guestWorkspace, $workflow] = shareWorkflowScene();

    $elsewhere = Workspace::factory()->create();
    Feature::for($elsewhere)->activate(SharedChannels::class);
    $theirs = channelWithMember($elsewhere, User::factory()->create());

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $theirs->id,
        'workspace' => $guestWorkspace->slug,
    ]);

    // A guest subletting the host's room to a third company.
    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(ChannelShare::query()->count())->toBe(0);
});

it('refuses to share where the other workspace does not accept shares', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    Feature::for($guestWorkspace)->deactivate(SharedChannels::class);

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => $guestWorkspace->slug,
    ]);

    // A beheerder who switched shared channels off should not find their people
    // in somebody else's channel because the invitation came from outside.
    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(ChannelShare::query()->count())->toBe(0);
});

it('stops sharing when this workspace has switched shared channels off', function () {
    [, $hostWorkspace, $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    Feature::for($hostWorkspace)->deactivate(SharedChannels::class);

    $run = runStep($workflow, 'share-channel', [
        'channel_id' => $channel->id,
        'workspace' => $guestWorkspace->slug,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed);
});

/*
 * Ending one.
 */

it('ends a share from the host side and clears out the guests', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    $visitor = User::factory()->create();
    joinWorkspace($guestWorkspace, $visitor);
    $channel->members()->attach($visitor->id, ['joined_at' => now()]);

    $run = runStep($workflow, 'revoke-channel-share', ['share_id' => $share->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($share->fresh()->revoked_at)->not->toBeNull()
        ->and(data_get($run->context, 'steps.0.revoked'))->toBeTrue()
        // A member list still naming somebody from a company that no longer has
        // access is a list that lies to everybody reading it.
        ->and($channel->members()->whereKey($visitor->id)->exists())->toBeFalse();
});

it('ends a share that is already ended without failing over it', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
        'revoked_at' => now()->subDay(),
    ]);

    $run = runStep($workflow, 'revoke-channel-share', ['share_id' => $share->id]);

    // A workflow that fires twice should not fail the second time for having
    // got what it wanted.
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.revoked'))->toBeFalse();
});

it('ends the share the trigger was about when none is named', function () {
    [, , $channel, $guestWorkspace, $workflow] = shareWorkflowScene();

    $share = ChannelShare::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $guestWorkspace->id,
    ]);

    $run = runStep($workflow, 'revoke-channel-share', [], [
        'trigger' => ['share' => ['id' => $share->id]],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($share->fresh()->revoked_at)->not->toBeNull();
});

it('will not end a share between two other workspaces', function () {
    [, , , , $workflow] = shareWorkflowScene();

    $one = Workspace::factory()->create();
    $two = Workspace::factory()->create();
    Feature::for($one)->activate(SharedChannels::class);
    Feature::for($two)->activate(SharedChannels::class);

    $theirs = ChannelShare::factory()->create([
        'channel_id' => channelWithMember($one, User::factory()->create())->id,
        'workspace_id' => $two->id,
    ]);

    $run = runStep($workflow, 'revoke-channel-share', ['share_id' => $theirs->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($theirs->fresh()->revoked_at)->toBeNull();
});
