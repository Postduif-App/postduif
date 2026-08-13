<?php

use App\Actions\SharedChannels\RespondToChannelShare;
use App\Actions\SharedChannels\RevokeChannelShare;
use App\Actions\SharedChannels\ShareChannelWithWorkspace;
use App\Actions\Workspace\CreateInviteLink;
use App\Actions\Workspace\RedeemInviteLink;
use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Features\InviteLinks;
use App\Features\SharedChannels;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\Triggers\ChannelShareOfferedTrigger;
use Laravel\Pennant\Feature;

/**
 * A host workspace with a channel, and a guest workspace to offer it to.
 *
 * Both run workflows, which is the whole point: the question this slice had to
 * answer is which of the two hears about what.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: User, 4: Workspace}
 */
function shareScene(): array
{
    $host = User::factory()->create(['name' => 'Sanne']);
    $hostSpace = workspaceWithMember($host, SystemRole::Admin);

    $guest = User::factory()->create(['name' => 'Joris']);
    $guestSpace = workspaceWithMember($guest, SystemRole::Admin);

    foreach ([$hostSpace, $guestSpace] as $workspace) {
        Feature::for($workspace)->activate(WorkflowsFeature::class);
        Feature::for($workspace)->activate(SharedChannels::class);
        Feature::for($workspace)->activate(InviteLinks::class);
    }

    $hostSpace->forceFill(['name' => 'Bouwbedrijf'])->save();
    $guestSpace->forceFill(['name' => 'Architect'])->save();

    return [$host, $hostSpace->refresh(), channelWithMember($hostSpace, $host), $guest, $guestSpace->refresh()];
}

/** A switched-on workflow in one particular workspace, with one harmless step. */
function watcherIn(Workspace $workspace, User $owner, string $trigger, array $config = []): Workflow
{
    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Poortwachter',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $workspace->channels()->value('id'),
    ])->create();

    return $workflow;
}

function watcherRun(Workflow $workflow): ?WorkflowRun
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->latest('id')->first();
}

/**
 * The decision this slice turns on: each trigger belongs to the side being told
 * something, never the side doing it.
 */
it('tells the guest about an offer and leaves the host alone', function () {
    [$host, $hostSpace, $channel, $guest, $guestSpace] = shareScene();

    $onHost = watcherIn($hostSpace, $host, 'channel-share-offered');
    $onGuest = watcherIn($guestSpace, $guest, 'channel-share-offered');

    app(ShareChannelWithWorkspace::class)->handle($channel, $guestSpace, $host);

    expect(watcherRun($onHost))->toBeNull()
        ->and(watcherRun($onGuest))->not->toBeNull();

    $context = watcherRun($onGuest)->context;

    // Both workspaces are described either way: which one you are is not
    // something a workflow should have to work out from empty paths.
    expect(data_get($context, 'trigger.host.name'))->toBe('Bouwbedrijf')
        ->and(data_get($context, 'trigger.guest.name'))->toBe('Architect')
        ->and(data_get($context, 'trigger.channel.id'))->toBe($channel->id)
        ->and(data_get($context, 'trigger.share.can_post'))->toBeTrue();
});

it('tells the host how the guest answered, and only about the answer it asked for', function () {
    [$host, $hostSpace, $channel, $guest, $guestSpace] = shareScene();

    $onYes = watcherIn($hostSpace, $host, 'channel-share-answered', ['answer' => 'accepted']);
    $onNo = watcherIn($hostSpace, $host, 'channel-share-answered', ['answer' => 'declined']);
    $onEither = watcherIn($hostSpace, $host, 'channel-share-answered', ['answer' => 'any']);
    $atTheGuest = watcherIn($guestSpace, $guest, 'channel-share-answered', ['answer' => 'any']);

    $share = app(ShareChannelWithWorkspace::class)->handle($channel, $guestSpace, $host);

    app(RespondToChannelShare::class)->handle($share, $guest, accepted: true);

    expect(watcherRun($onYes))->not->toBeNull()
        ->and(watcherRun($onNo))->toBeNull()
        ->and(watcherRun($onEither))->not->toBeNull()
        // The guest just answered; they need no telling.
        ->and($atTheGuest->runs()->count())->toBe(0)
        ->and(data_get(watcherRun($onYes)->context, 'trigger.share.accepted'))->toBeTrue();
});

it('tells the guest when a shared channel is taken back', function () {
    [$host, , $channel, $guest, $guestSpace] = shareScene();

    $onGuest = watcherIn($guestSpace, $guest, 'channel-share-revoked');

    $share = app(ShareChannelWithWorkspace::class)->handle($channel, $guestSpace, $host);
    app(RespondToChannelShare::class)->handle($share, $guest, accepted: true);

    app(RevokeChannelShare::class)->handle($share->refresh());

    expect(watcherRun($onGuest))->not->toBeNull()
        ->and(data_get(watcherRun($onGuest)->context, 'trigger.channel.name'))->toBe($channel->name);
});

/**
 * A workspace with shared channels switched off is protected twice over, and
 * the outer guard fires first: the offer itself is refused, so there is nothing
 * for the trigger to be asked about. Worth pinning down, because it is easy to
 * write the second check and believe the first one is what is being tested.
 */
it('stays out of a workspace that does not do shared channels', function () {
    [$host, , $channel, $guest, $guestSpace] = shareScene();

    $onGuest = watcherIn($guestSpace, $guest, 'channel-share-offered');

    Feature::for($guestSpace)->deactivate(SharedChannels::class);

    expect(fn () => app(ShareChannelWithWorkspace::class)->handle($channel, $guestSpace->refresh(), $host))
        ->toThrow(RuntimeException::class);

    expect(watcherRun($onGuest))->toBeNull()
        // And the trigger would refuse it too, which is the guard that matters
        // for a workspace that switches the feature off after the offer.
        ->and(ChannelShareOfferedTrigger::availableFor($onGuest->refresh()))->toBeFalse();
});

/*
 * Invitation links.
 */

it('runs when somebody new comes in through a link', function () {
    [$host, $hostSpace] = shareScene();

    $workflow = watcherIn($hostSpace, $host, 'invite-link-redeemed');

    $link = app(CreateInviteLink::class)->handle(
        $hostSpace,
        $host,
        $hostSpace->roles()->where('key', SystemRole::Member->value)->firstOrFail(),
        maxUses: 5,
    );

    $newcomer = User::factory()->create(['name' => 'Nieuwe']);

    expect(app(RedeemInviteLink::class)->handle($link, $newcomer))->toBeTrue();

    $run = watcherRun($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.user.name'))->toBe('Nieuwe')
        ->and(data_get($run->context, 'trigger.link.uses'))->toBe(1)
        ->and(data_get($run->context, 'trigger.link.uses_left'))->toBe(4);
});

/** Somebody who was already a member joined nothing and spent no use. */
it('says nothing when the link was followed by somebody already in', function () {
    [$host, $hostSpace] = shareScene();

    $workflow = watcherIn($hostSpace, $host, 'invite-link-redeemed');

    $link = app(CreateInviteLink::class)->handle(
        $hostSpace,
        $host,
        $hostSpace->roles()->where('key', SystemRole::Member->value)->firstOrFail(),
    );

    app(RedeemInviteLink::class)->handle($link, $host);

    expect(watcherRun($workflow))->toBeNull();
});

it('only runs for the role it was written about', function () {
    [$host, $hostSpace] = shareScene();

    $guestRole = $hostSpace->roles()->where('key', SystemRole::Guest->value)->firstOrFail();

    $onGuests = watcherIn($hostSpace, $host, 'invite-link-redeemed', ['role' => $guestRole->name]);
    $onColleagues = watcherIn($hostSpace, $host, 'invite-link-redeemed', ['role' => 'Lid']);

    $link = app(CreateInviteLink::class)->handle($hostSpace, $host, $guestRole);

    app(RedeemInviteLink::class)->handle($link, User::factory()->create());

    expect(watcherRun($onGuests))->not->toBeNull()
        ->and(watcherRun($onColleagues))->toBeNull();
});

it('mints a link from a step and hands over the address', function () {
    [$host, $hostSpace] = shareScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $hostSpace->id,
        'created_by' => $host->id,
    ]);

    $role = $hostSpace->roles()->where('key', SystemRole::Guest->value)->firstOrFail();

    $run = runStep($workflow, 'create-invite-link', [
        // By name and without regard for case, which is what somebody types.
        'role' => mb_strtolower($role->name),
        'max_uses' => 1,
        'valid_for_days' => 7,
    ]);

    $link = InviteLink::query()->where('workspace_id', $hostSpace->id)->firstOrFail();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($link->max_uses)->toBe(1)
        ->and($link->expires_at?->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($link->workspace_role_id)->toBe($role->id)
        ->and(data_get($run->context, 'steps.0.link.url'))->toContain($link->token)
        ->and(data_get($run->context, 'steps.0.link.role'))->toBe($role->name);
});

/** A misspelt role must not quietly become a colleague. */
it('refuses a role this workspace does not have', function () {
    [$host, $hostSpace] = shareScene();

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $hostSpace->id,
        'created_by' => $host->id,
    ]);

    $run = runStep($workflow, 'create-invite-link', ['role' => 'Halve gast']);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(InviteLink::query()->count())->toBe(0);
});
