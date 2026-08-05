<?php

use App\Enums\SystemRole;
use App\Features\Transfers;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

it('shows a member what they have standing out there', function () {
    [$user, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'title' => 'Offerte week 32',
        'max_downloads' => 5,
        'downloads' => 2,
    ]);

    $transfer->addMedia(UploadedFile::fake()->createWithContent('offerte.pdf', str_repeat('a', 500)))
        ->toMediaCollection(Transfer::FILES);

    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/transfers')
            ->has('transfers', 1)
            ->where('transfers.0.title', 'Offerte week 32')
            ->where('transfers.0.downloads', 2)
            ->where('transfers.0.maxDownloads', 5)
            ->where('transfers.0.fileCount', 1)
            ->where('transfers.0.size', 500)
            ->where('transfers.0.state', 'usable')
            ->where('seesEveryone', false)
        );
});

/**
 * The token is hidden on the model so it never leaves by accident. Here it is
 * asked for by name — a link you cannot read back after making it is one you
 * lose the moment you close the tab.
 */
it('hands the link back in full so it can be copied', function () {
    [$user, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfers.0.url', route('transfers.show', $transfer->token))
        );
});

it('does not show one colleague what another is sending', function () {
    [$sender, $workspace] = senderInWorkspace();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    actingAs($colleague)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 0));
});

/** Somebody has to be able to stop a mistake, so a beheerder sees the lot. */
it('shows whoever runs the workspace everything that is out there', function () {
    [$sender, $workspace] = senderInWorkspace();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);
    Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    actingAs($admin)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transfers', 2)
            ->where('seesEveryone', true)
        );
});

it('keeps another workspace out of the list', function () {
    [$user, $workspace] = senderInWorkspace();

    $elsewhere = User::factory()->create();
    $other = workspaceWithMember($elsewhere);
    Feature::for($other)->activate(Transfers::class);

    Transfer::factory()->create(['workspace_id' => $other->id, 'created_by' => $elsewhere->id]);
    Transfer::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $user->id]);

    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 1));
});

it('has no such screen in a workspace that never switched sending on', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)->get(route('chat.transfers.index', $workspace))->assertNotFound();
});

/**
 * A guest may not send, but may look — and what they see is their own empty
 * list. The screen says so through canSend rather than by refusing to open,
 * which is what keeps the button off the page instead of drawing one that
 * would be turned away.
 */
it('tells a guest they may look but not send', function () {
    [, $workspace] = senderInWorkspace();

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);

    actingAs($guest)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canSend', false));
});

it('shows the ceilings the form has to work within', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['max_transfer_kb' => 51200, 'max_transfer_days' => 5]);

    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('maxTransferKb', 51200)
            ->where('maxTransferDays', 5)
        );
});

/**
 * The navigation is drawn from shared props, so the entry has to appear and
 * disappear with the feature — a menu item that leads to a 404 is worse than no
 * menu item.
 */
it('puts the button in the rail only where the feature is on', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    /*
     * The rail reads workspace.transfers, which is null until the feature is
     * switched on and carries the ceilings once it is. One value answering both
     * "does this workspace have it" and "what may this member send", so the
     * button cannot appear above a screen that would 404.
     */
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.transfers', null));

    Feature::for($workspace)->activate(Transfers::class);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workspace.transfers.maxKb'));
});

it('opens inside the app shell rather than in settings', function () {
    Storage::fake('local');

    [$user, $workspace] = senderInWorkspace();

    /*
     * The move this screen made: sending a file to somebody is ordinary work,
     * so it lives beside the channels with the same sidebar and the same live
     * connection — not behind a settings menu, which filed it as administration
     * and put it two clicks further away.
     */
    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/transfers')
            ->has('channels')
            ->has('activeThreads')
            ->where('workspace.slug', $workspace->slug));
});

it('no longer answers on the old settings address', function () {
    expect(Route::has('workspace.transfers.index'))->toBeFalse();
});
