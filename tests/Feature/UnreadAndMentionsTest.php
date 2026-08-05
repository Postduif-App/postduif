<?php

use App\Actions\Chat\MarkChannelRead;
use App\Actions\Chat\SendMessage;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Put a second member in the same channel, since a channel with one person in
 * it can never have anything unread.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function channelWithTwoMembers(): array
{
    $reader = User::factory()->create(['username' => 'reader']);
    $writer = User::factory()->create(['username' => 'writer']);

    $workspace = workspaceWithMember($reader);
    $workspace->members()->attach($writer->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    $channel = channelWithMember($workspace, $reader);
    $channel->members()->attach($writer->id, ['joined_at' => now()]);

    return [$reader, $writer, $workspace, $channel];
}

it('counts messages from others as unread', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();
    $other = channelWithMember($workspace, $reader);

    app(SendMessage::class)->handle($channel, $writer, 'Eén');
    app(SendMessage::class)->handle($channel, $writer, 'Twee');

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->firstWhere('id', $channel->id)['unreadCount'] === 2
            )
        );
});

/**
 * Sending already advances the author's read pointer, so posting through
 * SendMessage would prove nothing here. Write the message directly and leave
 * the pointer behind it, which is the only state in which the author filter in
 * CountUnread actually does any work.
 */
it('never counts your own messages as unread', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();
    $other = channelWithMember($workspace, $reader);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $reader->id,
        'body' => 'Van mezelf',
    ]);
    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $writer->id,
        'body' => 'Van iemand anders',
    ]);

    expect($channel->members()->find($reader->id)->pivot->last_read_message_id)
        ->toBeNull();

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->firstWhere('id', $channel->id)['unreadCount'] === 1
            )
        );
});

it('clears the unread count when the channel is opened', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();
    $other = channelWithMember($workspace, $reader);

    app(SendMessage::class)->handle($channel, $writer, 'Lees mij');

    actingAs($reader)->get(route('chat.show', [$workspace, $channel]));

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->firstWhere('id', $channel->id)['unreadCount'] === 0
            )
        );
});

it('carries no badge for the channel you are looking at', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();

    app(SendMessage::class)->handle($channel, $writer, 'Lees mij @reader');

    // The sidebar is built after the channel is marked read, so the very page
    // that shows the messages never also claims they are unread.
    actingAs($reader)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', function ($channels) use ($channel) {
                $row = collect($channels)->firstWhere('id', $channel->id);

                return $row['unreadCount'] === 0 && $row['mentionCount'] === 0;
            })
        );
});

it('leaves thread replies out of the channel unread count', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();
    $other = channelWithMember($workspace, $reader);

    $parent = app(SendMessage::class)->handle($channel, $writer, 'Wortel');
    app(SendMessage::class)->handle($channel, $writer, 'Antwoord', $parent->id);

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->firstWhere('id', $channel->id)['unreadCount'] === 1
            )
        );
});

it('records a mention for a channel member', function () {
    [$reader, $writer, , $channel] = channelWithTwoMembers();

    $message = app(SendMessage::class)->handle($channel, $writer, 'Hoi @reader, kun je kijken?');

    expect(InboxItem::where('message_id', $message->id)->pluck('user_id')->all())
        ->toBe([$reader->id]);
});

it('does not mention someone who is not in the channel', function () {
    [, $writer, $workspace, $channel] = channelWithTwoMembers();
    User::factory()->create(['username' => 'outsider']);

    $message = app(SendMessage::class)->handle($channel, $writer, 'Hallo @outsider');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0)
        ->and($workspace->exists)->toBeTrue();
});

it('does not mention the author of the message', function () {
    [, $writer, , $channel] = channelWithTwoMembers();

    $message = app(SendMessage::class)->handle($channel, $writer, 'Ik ben @writer');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0);
});

it('treats an email address as plain text', function () {
    [, $writer, , $channel] = channelWithTwoMembers();

    $message = app(SendMessage::class)->handle($channel, $writer, 'Mail naar hallo@reader.nl');

    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0);
});

it('surfaces unread mentions in the sidebar and clears them on open', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();
    $other = channelWithMember($workspace, $reader);

    app(SendMessage::class)->handle($channel, $writer, 'Kijk even @reader');

    $mentionCount = fn ($channels) => collect($channels)
        ->firstWhere('id', $channel->id)['mentionCount'];

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page->where('channels', fn ($c) => $mentionCount($c) === 1));

    actingAs($reader)->get(route('chat.show', [$workspace, $channel]));

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $other]))
        ->assertInertia(fn ($page) => $page->where('channels', fn ($c) => $mentionCount($c) === 0));
});

it('never drags the read pointer backwards', function () {
    [$reader, $writer, $workspace, $channel] = channelWithTwoMembers();

    app(SendMessage::class)->handle($channel, $writer, 'Eerste');
    actingAs($reader)->get(route('chat.show', [$workspace, $channel]));
    $pointer = $channel->members()->find($reader->id)->pivot->last_read_message_id;

    // An older message arriving late must not reopen everything after it.
    app(MarkChannelRead::class)
        ->handle($channel, $reader, Str::lower((string) Str::ulid(now()->subDay())));

    expect($channel->members()->find($reader->id)->pivot->last_read_message_id)
        ->toBe($pointer);
});

it('gives every registered user a unique handle', function () {
    Message::query()->delete();
    User::query()->delete();

    $this->post(route('register'), [
        'name' => 'Fenna de Vries',
        'email' => 'fenna@example.com',
        'password' => 'wachtwoord-voor-test',
        'password_confirmation' => 'wachtwoord-voor-test',
    ]);

    $this->post(route('logout'));

    $this->post(route('register'), [
        'name' => 'Fenna de Vries',
        'email' => 'fenna2@example.com',
        'password' => 'wachtwoord-voor-test',
        'password_confirmation' => 'wachtwoord-voor-test',
    ]);

    expect(User::orderBy('id')->pluck('username')->all())
        ->toBe(['fenna.de.vries', 'fenna.de.vries2']);
});

it('keeps a private channel out of the unread counts entirely', function () {
    $reader = User::factory()->create();
    $workspace = workspaceWithMember($reader);
    $visible = channelWithMember($workspace, $reader);

    $secret = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);
    $insider = User::factory()->create();
    $secret->members()->attach($insider->id, ['joined_at' => now()]);
    app(SendMessage::class)->handle($secret, $insider, 'Geheim');

    actingAs($reader)
        ->get(route('chat.show', [$workspace, $visible]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->doesntContain('id', $secret->id)
            )
        );
});

/**
 * The "@" has to start a word. MessageBody applies the same rule when deciding
 * what to highlight, so if these two drift apart the interface starts promising
 * notifications that never get sent.
 */
it('only treats an at sign at the start of a word as a mention', function () {
    [$reader, $writer, , $channel] = channelWithTwoMembers();

    $mentions = fn (string $body) => InboxItem::where(
        'message_id',
        app(SendMessage::class)->handle($channel, $writer, $body)->id
    )->count();

    expect($mentions('Hoi @reader'))->toBe(1)
        ->and($mentions("Op een nieuwe regel\n@reader kijk even"))->toBe(1)
        ->and($mentions('Punt erachter @reader.'))->toBe(1)
        ->and($mentions('Midden in een woord bla@reader'))->toBe(0)
        ->and($mentions('Mailadres hallo@reader.nl'))->toBe(0);

    expect($reader->exists)->toBeTrue();
});

it('does not treat a channel reference as a mention', function () {
    [, $writer, , $channel] = channelWithTwoMembers();

    $message = app(SendMessage::class)->handle(
        $channel,
        $writer,
        'Zie ook #'.$channel->name.' voor de details',
    );

    // A channel reference notifies nobody, so there is nothing to record.
    expect(InboxItem::where('message_id', $message->id)->count())->toBe(0);
});
