<?php

use App\Enums\TransferAudience;
use App\Models\Message;
use App\Models\Transfer;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A message in a channel carrying a link to a transfer from the same workspace.
 *
 * @return array{0: Message, 1: Transfer}
 */
function messageWithTransferLink(array $state = [], int $files = 2): array
{
    [$sender, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $sender);

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'title' => 'Offerte week 32',
        ...$state,
    ]);

    for ($i = 1; $i <= $files; $i++) {
        $transfer->addMedia(UploadedFile::fake()->createWithContent("bestand-{$i}.txt", str_repeat('a', 500)))
            ->toMediaCollection(Transfer::FILES);
    }

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $sender->id,
        'body' => 'Hier staat het: '.route('transfers.show', $transfer->token),
    ]);

    return [$message, $transfer->refresh()];
}

it('says what a transfer link is carrying instead of showing a bare token', function () {
    [$message] = messageWithTransferLink();

    expect(present($message)['transferCard'])
        ->title->toBe('Offerte week 32')
        ->fileCount->toBe(2)
        ->size->toBe(1000)
        ->state->toBe('usable')
        ->isLocked->toBeFalse();
});

/** Nothing is fetched: the route is ours and the answer is a row we already have. */
it('needs no link preview to do it', function () {
    [$message] = messageWithTransferLink();

    $presented = present($message);

    expect($presented['transferCard'])->not->toBeNull()
        ->and($presented['linkPreview'])->toBeNull();
});

it('leaves an ordinary message alone', function () {
    [$sender, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $sender);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'body' => 'Kijk eens op https://voorbeeld.nl',
    ]);

    expect(present($message)['transferCard'])->toBeNull();
});

/**
 * A link pasted from elsewhere is somebody else's. Drawing its title here would
 * carry the contents of one workspace into another on the strength of a URL.
 */
it('does not draw a transfer from another workspace', function () {
    Storage::fake('local');

    [$sender, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $sender);

    $elsewhere = Workspace::factory()->create();
    $stranger = Transfer::factory()->create([
        'workspace_id' => $elsewhere->id,
        'title' => 'Niet van ons',
    ]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'body' => route('transfers.show', $stranger->token),
    ]);

    expect(present($message)['transferCard'])->toBeNull();
});

/** So the channel shows a dead link as dead, rather than leaving somebody to click. */
it('shows in the channel that a link has stopped working', function (array $state, string $expected) {
    [$message] = messageWithTransferLink($state);

    expect(present($message)['transferCard']['state'])->toBe($expected);
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'expired'],
    'withdrawn' => [['revoked_at' => now()->subHour()], 'revoked'],
    'used up' => [['max_downloads' => 1, 'downloads' => 1], 'exhausted'],
]);

/**
 * The shared token opens nothing for that audience, so a card would advertise
 * something nobody reading the channel can open.
 */
it('draws nothing for a transfer addressed to named people', function () {
    [$message] = messageWithTransferLink(['audience' => TransferAudience::NamedRecipients]);

    expect(present($message)['transferCard'])->toBeNull();
});

it('says when there is a password, so nobody is surprised by it', function () {
    [$message] = messageWithTransferLink(['password' => bcrypt('geheim123')]);

    expect(present($message)['transferCard']['isLocked'])->toBeTrue();
});

it('draws nothing for a token that stands for nothing', function () {
    [$sender, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $sender);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'body' => route('transfers.show', str_repeat('z', 64)),
    ]);

    expect(present($message)['transferCard'])->toBeNull();
});

/**
 * A deleted message is a tombstone. A card under it would be half of the thing
 * that was taken back — the same reasoning that withholds its files.
 */
it('draws nothing under a deleted message', function () {
    [$message] = messageWithTransferLink();

    $message->delete();

    expect(present($message->fresh(['media'])->refresh())['transferCard'])->toBeNull();
});
