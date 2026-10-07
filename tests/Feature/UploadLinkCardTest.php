<?php

use App\Models\Message;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Models\Workspace;

/**
 * A message in a channel carrying a link to an upload link from the same
 * workspace.
 *
 * @return array{0: Message, 1: UploadLink}
 */
function messageWithUploadLink(array $state = []): array
{
    [$link, $workspace, $owner] = openUploadLink($state);
    $channel = channelWithMember($workspace, $owner);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'body' => 'Stuur het hier maar in: '.route('upload-links.show', $link->token),
    ]);

    return [$message, $link];
}

it('says what an upload link is for instead of showing a bare token', function () {
    [$message, $link] = messageWithUploadLink(['max_uploads' => 3, 'uploads' => 1]);

    expect(present($message)['uploadLinkCard'])
        ->title->toBe('Jaarstukken 2025')
        ->state->toBe('usable')
        ->isLocked->toBeFalse()
        ->uploadsLeft->toBe(2)
        ->url->toBe(route('upload-links.show', $link->token));
});

it('shows a closed link as closed', function () {
    [$message] = messageWithUploadLink(['revoked_at' => now()->subHour()]);

    expect(present($message)['uploadLinkCard']['state'])->toBe('revoked');
});

it('says a password is needed before anybody clicks', function () {
    [$message] = messageWithUploadLink();
    UploadLink::sole()->update(['password' => bcrypt('geheim123')]);

    expect(present($message)['uploadLinkCard']['isLocked'])->toBeTrue();
});

/** The whole channel sees the card, often with the customer reading along. */
it('never says who already sent something in', function () {
    [$message, $link] = messageWithUploadLink();
    UploadLinkSubmission::factory()->create(['upload_link_id' => $link->id, 'name' => 'Anna de Vries']);

    expect(json_encode(present($message)['uploadLinkCard']))->not->toContain('Anna');
});

it('draws nothing for a link from another workspace', function () {
    [$message, $link] = messageWithUploadLink();
    $link->update(['workspace_id' => Workspace::factory()->create()->id]);

    expect(present($message)['uploadLinkCard'])->toBeNull();
});

it('leaves an ordinary message alone', function () {
    [$message] = messageWithUploadLink();
    $message->update(['body' => 'Gewoon een bericht']);

    expect(present($message->refresh())['uploadLinkCard'])->toBeNull();
});
