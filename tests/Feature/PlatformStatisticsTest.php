<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Transfer;
use App\Models\User;
use App\Support\PlatformStatistics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * No test here is about the websocket server — SocketPresenceTest is — but
 * every one of them renders the section it appears in, and none of them may go
 * looking for a Reverb on the machine running the suite.
 */
beforeEach(function () {
    reverbIsUp(connections: 0);
});

/**
 * A platform with something on it: a workspace, a channel of each kind, a
 * message with a reply, and a file.
 *
 * @return array{0: User, 1: Channel}
 */
function countablePlatform(): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
    ]);

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    $parent = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        'parent_id' => $parent->id,
    ]);

    $parent
        ->addMedia(UploadedFile::fake()->createWithContent('notulen.txt', str_repeat('a', 2048)))
        ->toMediaCollection(Message::ATTACHMENTS);

    return [$author, $channel];
}

it('counts everything on the platform', function () {
    countablePlatform();

    expect(app(PlatformStatistics::class)())
        ->toMatchArray([
            'Workspaces' => '1',
            // Two: the author, and the owner the workspace factory makes.
            'Users' => '2',
            'Channels' => '2',
            'Messages' => '2 (1 in threads)',
            'Attachments' => '1 (2 KB)',
            'Transfers' => '0',
        ]);
});

it('gives every channel kind a line of its own with nothing but a number on it', function () {
    countablePlatform();

    /*
     * The parts have to add up to the total above them, which is the whole
     * reason a kind with nothing in it still gets a line.
     */
    expect(app(PlatformStatistics::class)())
        ->toMatchArray([
            'Channels' => '2',
            'Channels public' => '1',
            'Channels private' => '1',
            'Channels direct' => '0',
        ]);
});

it('names the suspended accounts only when there are any', function () {
    User::factory()->create();

    expect(app(PlatformStatistics::class)()['Users'])->toBe('1');

    User::factory()->suspended()->create();

    expect(app(PlatformStatistics::class)()['Users'])->toBe('2 (1 suspended)');
});

it('drops a withdrawn message from the count but keeps its file on the disk', function () {
    [$author] = countablePlatform();

    Message::query()->whereNull('parent_id')->first()->delete();

    /*
     * The message is gone and the bytes are not: media library leaves the file
     * alone when the model is soft-deleted, and the number that says what this
     * platform is storing has to say so.
     */
    expect(app(PlatformStatistics::class)())
        ->toMatchArray([
            'Messages' => '1 (1 in threads)',
            'Attachments' => '1 (2 KB)',
        ]);
});

it('keeps transfer files apart from message attachments', function () {
    [$transfer] = waitingTransfer();

    $statistics = app(PlatformStatistics::class)();

    expect($statistics['Transfers'])->toBe('1 (64 B)')
        ->and($statistics['Attachments'])->toBe('0')
        ->and($transfer->getMedia(Transfer::FILES))->toHaveCount(1);
});

/*
 * Through Artisan::call rather than the artisan() helper: the about command
 * prints with the console components, which write straight to the output rather
 * than through the methods PendingCommand's expectation mock listens on — so
 * expectsOutputToContain would fail on output that is plainly there.
 */
it('reports the numbers through the about command', function () {
    countablePlatform();

    expect(Artisan::call('about', ['--only' => 'postduif']))->toBe(0);

    expect(Artisan::output())
        ->toContain('Channels public')
        ->toContain('Channels private')
        ->toContain('Channels direct')
        ->toContain('2 (1 in threads)');
});

it('reports who is on the socket server beside what is in the database', function () {
    reverbIsUp(connections: 7, rosters: ['acme' => [1, 2, 3, 4]]);

    expect(app(PlatformStatistics::class)())
        ->toMatchArray([
            'Online' => '4',
            'Socket connections' => '7',
        ]);
});

it('keeps a database it cannot reach apart from a socket server it cannot reach', function () {
    Storage::fake('local');

    reverbIsDown();

    Schema::drop('media');

    /*
     * Two failures, two lines. One line saying "everything is broken" would
     * leave whoever is reading it no better off than before they asked.
     */
    expect(app(PlatformStatistics::class)())
        ->toBe([
            'Statistics' => 'database unreachable',
            'Online' => 'reverb unreachable',
        ]);
});

it('stands aside rather than taking the about command down with it', function () {
    /*
     * What somebody meets on a checkout that has never been migrated. The other
     * sections — the PHP version, the cache driver — are the reason they ran
     * the command, and they must survive a section that cannot answer.
     */
    Storage::fake('local');

    Schema::drop('media');

    /*
     * One line for the counts, and the websocket server still answering on its
     * own — the two do not fail together and must not be reported as if they
     * did.
     */
    expect(app(PlatformStatistics::class)())
        ->toBe([
            'Statistics' => 'database unreachable',
            'Online' => '0',
            'Socket connections' => '0',
        ]);
});
