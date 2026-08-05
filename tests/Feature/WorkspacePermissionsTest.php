<?php

use App\Enums\AttachmentType;
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
        );
});

it('refuses the rules screen to a plain member', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Member);

    actingAs($user)->get(route('workspace.permissions.edit'))->assertForbidden();
});

it('leaves the name alone — that is the other screen', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'name' => 'Gekaapt',
        ])
        ->assertRedirect();

    expect($workspace->fresh()->name)->not->toBe('Gekaapt');
});

it('refuses a rule change from a plain member', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Member);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            'uploads_enabled' => 0,
        ])
        ->assertForbidden();

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
    ]);

    expect($workspace->fresh()->link_previews_enabled)->toBeTrue();
});
