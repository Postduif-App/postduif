<?php

use App\Enums\AttachmentType;
use App\Enums\BroadcastMentionPolicy;
use App\Enums\ChannelCreationPolicy;
use App\Enums\SystemRole;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows both rules with everything they can be set to', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->get(route('workspace.permissions.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace-permissions')
            ->where('workspace.broadcastMentions', BroadcastMentionPolicy::Admins->value)
            ->where('workspace.channelCreation', ChannelCreationPolicy::Everyone->value)
            ->has('broadcastMentionOptions', count(BroadcastMentionPolicy::cases()))
            ->has('channelCreationOptions', count(ChannelCreationPolicy::cases()))
        );
});

it('refuses the rules screen to a plain member', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Member);

    actingAs($user)->get(route('workspace.permissions.edit'))->assertForbidden();
});

it('saves both rules at once', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Everyone->value,
            'channel_creation' => ChannelCreationPolicy::Admins->value,
        ])
        ->assertRedirect();

    expect($workspace->fresh())
        ->broadcast_mentions->toBe(BroadcastMentionPolicy::Everyone)
        ->channel_creation->toBe(ChannelCreationPolicy::Admins);
});

it('leaves the name alone — that is the other screen', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'name' => 'Gekaapt',
            'broadcast_mentions' => BroadcastMentionPolicy::Nobody->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
        ])
        ->assertRedirect();

    expect($workspace->fresh()->name)->not->toBe('Gekaapt');
});

it('refuses a rule that is not one of the options', function (string $field) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
            $field => 'anyone-really',
        ])
        ->assertSessionHasErrors($field);

    expect($workspace->fresh())
        ->broadcast_mentions->toBe(BroadcastMentionPolicy::Admins)
        ->channel_creation->toBe(ChannelCreationPolicy::Everyone);
})->with(['broadcast_mentions', 'channel_creation']);

it('refuses a rule change from a plain member', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Member);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Everyone->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
        ])
        ->assertForbidden();

    expect($workspace->fresh()->broadcast_mentions)->toBe(BroadcastMentionPolicy::Admins);
});

it('shows what the workspace accepts as an attachment', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->get(route('workspace.permissions.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.uploadsEnabled', true)
            ->where('workspace.allowedAttachmentTypes', AttachmentType::defaults())
            ->where('workspace.maxAttachmentKb', 10240)
            ->has('attachmentTypeOptions', count(AttachmentType::cases()))
        );
});

it('saves what may be shared and how large', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
            'uploads_enabled' => '1',
            'allowed_attachment_types' => [AttachmentType::Images->value],
            'max_attachment_mb' => 25,
        ])
        ->assertRedirect();

    expect($workspace->fresh())
        ->uploads_enabled->toBeTrue()
        ->allowed_attachment_types->toBe([AttachmentType::Images->value])
        // Asked in megabytes, stored in kilobytes.
        ->max_attachment_kb->toBe(25600);
});

it('keeps the earlier choices when sharing is switched off', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);
    $workspace->update([
        'allowed_attachment_types' => [AttachmentType::Archives->value],
        'max_attachment_kb' => 51200,
    ]);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
            'uploads_enabled' => '0',
        ])
        ->assertRedirect();

    expect($workspace->fresh())
        ->uploads_enabled->toBeFalse()
        ->allowed_attachment_types->toBe([AttachmentType::Archives->value])
        ->max_attachment_kb->toBe(51200);
});

it('refuses to allow sharing with nothing allowed', function () {
    $user = User::factory()->create();

    workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
            'uploads_enabled' => '1',
            'allowed_attachment_types' => [],
            'max_attachment_mb' => 10,
        ])
        ->assertSessionHasErrors('allowed_attachment_types');
});

it('refuses a size nobody could actually upload', function () {
    $user = User::factory()->create();

    workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
            'channel_creation' => ChannelCreationPolicy::Everyone->value,
            'uploads_enabled' => '1',
            'allowed_attachment_types' => [AttachmentType::Images->value],
            'max_attachment_mb' => 5000,
        ])
        ->assertSessionHasErrors('max_attachment_mb');
});

it('shows whether the server may open shared links', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->get(route('workspace.permissions.edit'))
        ->assertOk()
        // Off unless somebody said otherwise: this is the one setting where the
        // server talks outward on a member's behalf.
        ->assertInertia(fn ($page) => $page->where('workspace.linkPreviewsEnabled', false));
});

it('turns link previews on and off again', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    $rules = [
        'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
        'channel_creation' => ChannelCreationPolicy::Everyone->value,
    ];

    actingAs($user)->patch(route('workspace.permissions.update'), [
        ...$rules,
        'link_previews_enabled' => '1',
    ])->assertRedirect();

    expect($workspace->fresh()->link_previews_enabled)->toBeTrue();

    actingAs($user)->patch(route('workspace.permissions.update'), [
        ...$rules,
        'link_previews_enabled' => '0',
    ]);

    expect($workspace->fresh()->link_previews_enabled)->toBeFalse();
});

/** A request that says nothing about previews leaves them as they were. */
it('leaves link previews alone when the form does not mention them', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);
    $workspace->update(['link_previews_enabled' => true]);

    actingAs($user)->patch(route('workspace.permissions.update'), [
        'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
        'channel_creation' => ChannelCreationPolicy::Everyone->value,
    ]);

    expect($workspace->fresh()->link_previews_enabled)->toBeTrue();
});
