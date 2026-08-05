<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A member with a role that holds one particular right, and a bot message for
 * them to try it on.
 *
 * The member is deliberately nobody special: not the channel's creator, not a
 * workspace admin, not a platform moderator. Every one of those could already
 * delete this before the right existed, so a fixture that happened to be one of
 * them would pass whatever the policy said.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message}
 */
function botMessageScene(?WorkspaceAbility $ability = null): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    $member = User::factory()->create();
    joinWorkspace($workspace, $member);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    $channel->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

    if ($ability !== null) {
        $role = $workspace->roleFor($member);
        $role->forceFill(['abilities' => [...$role->abilities, $ability->value]])->save();
    }

    $message = Message::factory()->fromBot()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    return [$member, $workspace, $channel, $message];
}

it('lets a role that holds the right delete what a bot posted', function () {
    [$member, $workspace, $channel, $message] = botMessageScene(WorkspaceAbility::DeleteBotMessages);

    actingAs($member)
        ->delete(route('chat.messages.destroy', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect($message->refresh()->isDeleted())->toBeTrue();
});

it('refuses an ordinary member without it', function () {
    [$member, $workspace, $channel, $message] = botMessageScene();

    actingAs($member)
        ->delete(route('chat.messages.destroy', [$workspace, $channel, $message]))
        ->assertForbidden();

    expect($message->refresh()->isDeleted())->toBeFalse();
});

it('leaves what people wrote themselves alone', function () {
    [$member, $workspace, $channel] = botMessageScene(WorkspaceAbility::DeleteBotMessages);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);

    $written = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $colleague->id,
    ]);

    // The whole point of keeping this a right about *bots*: it must not become
    // a way to take down a colleague's words.
    actingAs($member)
        ->delete(route('chat.messages.destroy', [$workspace, $channel, $written]))
        ->assertForbidden();

    expect($written->refresh()->isDeleted())->toBeFalse();
});

it('keeps the rule that was already there for whoever runs the channel', function () {
    [, $workspace, $channel, $message] = botMessageScene();

    // The channel's creator, who has held this since before the right existed
    // and must not have lost it by the right being added beside them.
    $owner = User::query()->whereKey($channel->created_by)->firstOrFail();

    actingAs($owner)
        ->delete(route('chat.messages.destroy', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect($message->refresh()->isDeleted())->toBeTrue();
});

it('offers the right on the permissions screen, switched off', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    expect($workspace->allows($admin, WorkspaceAbility::DeleteBotMessages))->toBeFalse()
        ->and(WorkspaceAbility::values())->toContain('delete-bot-messages');
});

it('lets an owner hand out every right there is', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);

    /*
     * The guard on the role screen refuses any right the editor does not hold
     * themselves, so a right the owner lacks is one nobody in the workspace can
     * ever switch on — for any role. This is the test that keeps the next
     * default-off ability from quietly locking itself out.
     */
    $missing = collect(WorkspaceAbility::cases())
        ->reject(fn (WorkspaceAbility $ability): bool => $workspace->allows($owner, $ability))
        ->map(fn (WorkspaceAbility $ability): string => $ability->value);

    expect($missing)->toBeEmpty();
});

it('stops an administrator short of the rights an owner keeps', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    // Not a ladder: an administrator runs the workspace, and reading a
    // colleague's hours does not follow from that.
    expect($workspace->allows($admin, WorkspaceAbility::SeeHours))->toBeFalse()
        ->and($workspace->allows($admin, WorkspaceAbility::ManageWorkspace))->toBeTrue();
});

it('writes the new rights into a role through the screen', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);

    $role = $workspace->roles()->where('key', SystemRole::Member->value)->firstOrFail();

    actingAs($owner)
        ->patch(route('workspace.roles.update', $role), [
            'name' => $role->name,
            'is_external' => false,
            'abilities' => [
                ...$role->abilities,
                WorkspaceAbility::DeleteBotMessages->value,
                WorkspaceAbility::SeeHours->value,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($role->refresh()->allows(WorkspaceAbility::DeleteBotMessages))->toBeTrue()
        ->and($role->allows(WorkspaceAbility::SeeHours))->toBeTrue();
});

it('tells the conversation whether to draw the bin on a bot message', function () {
    [$member, $workspace, $channel] = botMessageScene(WorkspaceAbility::DeleteBotMessages);

    /*
     * The policy alone was not enough: the browser decides whether the button
     * exists, and it cannot work this out — the answer involves the channel,
     * the role and platform moderation at once.
     */
    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canDeleteBotMessages', true));

    /*
     * And a workspace that granted nothing. A second scene rather than a second
     * person in the first one: the right sits on the *role*, and every ordinary
     * member here shares one — so a colleague in the same workspace would have
     * it too, correctly, and prove nothing.
     */
    [$without, $otherWorkspace, $otherChannel] = botMessageScene();

    actingAs($without)
        ->get(route('chat.show', [$otherWorkspace, $otherChannel]))
        ->assertInertia(fn ($page) => $page->where('channel.canDeleteBotMessages', false));
});
