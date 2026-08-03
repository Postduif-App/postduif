<?php

use App\Actions\Chat\PresentMessage;
use App\Enums\ChannelPostingPolicy;
use App\Enums\TransferAudience;
use App\Enums\WorkspaceRole;
use App\Features\Transfers;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/** @return array<string, mixed> */
function announcedPayload(Channel $channel, array $overrides = []): array
{
    return [
        'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
        'valid_for_days' => 7,
        'audience' => TransferAudience::WorkspaceMembers->value,
        'channel_id' => $channel->id,
        ...$overrides,
    ];
}

it('puts the link in the channel it was sent from', function () {
    [$user, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($channel, [
            'title' => 'Offerte week 32',
        ]))
        ->assertRedirect();

    $transfer = Transfer::sole();
    $message = Message::sole();

    expect($message)
        ->channel_id->toBe($channel->id)
        ->user_id->toBe($user->id);

    expect($message->body)
        ->toContain(route('transfers.show', $transfer->token))
        ->toContain('Offerte week 32');
});

/**
 * An ordinary message, link and all — what makes it read as more than a token
 * is the card PresentMessage draws, which works for a pasted link too.
 */
it('posts it as an ordinary message rather than a kind of its own', function () {
    [$user, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($channel))
        ->assertRedirect();

    expect(Message::sole())
        ->webhook_id->toBeNull()
        ->bot_name->toBeNull()
        ->parent_id->toBeNull();
});

it('says nothing in any channel when none was named', function () {
    [$user, $workspace] = senderInWorkspace();
    channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::Everyone->value,
        ])
        ->assertRedirect();

    expect(Transfer::count())->toBe(1)
        ->and(Message::count())->toBe(0);
});

/**
 * Being allowed to send files is not being allowed to speak everywhere. Without
 * this, a transfer would be a way to put a line into a conversation you may not
 * post in.
 */
it('refuses to announce in a channel this member may not post in', function () {
    [$user, $workspace] = senderInWorkspace();

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'posting_policy' => ChannelPostingPolicy::Admins,
    ]);
    $channel->members()->attach($user->id, ['joined_at' => now()]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($channel))
        ->assertForbidden();

    expect(Transfer::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

it('refuses a channel this member is not even in', function () {
    [$user, $workspace] = senderInWorkspace();
    $closed = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($closed))
        ->assertForbidden();

    expect(Transfer::count())->toBe(0);
});

/** The id is in the payload, so a channel from elsewhere must not resolve. */
it('refuses a channel from another workspace', function () {
    [$user, $workspace] = senderInWorkspace();

    $elsewhere = Workspace::factory()->create();
    $stranger = Channel::factory()->create(['workspace_id' => $elsewhere->id]);
    $stranger->members()->attach($user->id, ['joined_at' => now()]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($stranger))
        ->assertSessionHasErrors('channel_id');

    expect(Transfer::count())->toBe(0);
});

/**
 * authorize() runs before the rules do, so at that point channel_id is whatever
 * arrived. An array would otherwise turn the lookup into a query for several
 * rows.
 */
it('is not fooled by a channel id that is not a number', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload(
            Channel::factory()->create(['workspace_id' => $workspace->id]),
            ['channel_id' => ['1', '2']],
        ))
        ->assertSessionHasErrors('channel_id');

    expect(Transfer::count())->toBe(0);
});

it('does not let a guest announce a transfer either', function () {
    [, $workspace] = senderInWorkspace();

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => WorkspaceRole::Guest->value,
        'joined_at' => now(),
    ]);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($channel))
        ->assertForbidden();
});

/** The card is what makes the link readable, so the two have to meet. */
it('lands as a message the channel draws a card for', function () {
    [$user, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), announcedPayload($channel, [
            'title' => 'Offerte week 32',
            // Members-only, so the shared token still opens it — a card is
            // drawn for every audience except named recipients.
            'audience' => TransferAudience::WorkspaceMembers->value,
        ]))
        ->assertRedirect();

    $card = app(PresentMessage::class)->handle(Message::sole())['transferCard'];

    expect($card)
        ->not->toBeNull()
        ->title->toBe('Offerte week 32')
        ->fileCount->toBe(1);
});

/**
 * The composer only draws the button when both are true: the workspace offers
 * the feature and this member may use it. A button that the endpoint would
 * refuse is worse than no button.
 */
it('offers the message field nothing where sending is not allowed', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.transfers', null));

    Feature::for($workspace)->activate(Transfers::class);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.transfers.maxKb', $workspace->max_transfer_kb)
            ->where('workspace.transfers.maxDays', $workspace->max_transfer_days)
        );
});

it('offers a guest nothing, even where the workspace has it on', function () {
    [, $workspace] = senderInWorkspace();

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => WorkspaceRole::Guest->value,
        'joined_at' => now(),
    ]);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.transfers', null));
});
