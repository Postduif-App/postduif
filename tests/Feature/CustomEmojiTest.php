<?php

use App\Enums\SystemRole;
use App\Models\CustomEmoji;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Somebody who may manage a workspace, in a workspace with a faked disk.
 *
 * The disk is faked here rather than in each test: every one of these either
 * writes a file or checks that one is gone, and a suite that leaves real webp
 * files in storage is one nobody notices until the day it matters.
 *
 * @return array{0: User, 1: Workspace}
 */
function emojiAdmin(): array
{
    Storage::fake('local');

    $admin = User::factory()->create();

    return [$admin, workspaceWithMember($admin, SystemRole::Admin)];
}

it('stores an uploaded picture under the name it was given', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)
        ->post(route('workspace.emoji.store'), [
            'name' => 'shipit',
            'image' => UploadedFile::fake()->image('logo.png', 400, 400),
        ])
        ->assertRedirect();

    $emoji = CustomEmoji::firstOrFail();

    expect($emoji->name)->toBe('shipit')
        ->and($emoji->shortcode())->toBe(':shipit:')
        ->and($emoji->mime)->toBe('image/webp')
        ->and(Storage::disk('local')->exists($emoji->path))->toBeTrue();
});

it('shrinks what it stores', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'groot',
        'image' => UploadedFile::fake()->image('logo.png', 900, 600),
    ]);

    $stored = (string) Storage::disk('local')->get(CustomEmoji::firstOrFail()->path);
    $size = getimagesizefromstring($stored);

    // Contained rather than cropped: a symbol that loses its edges is not the
    // symbol any more, so the long side is what meets the limit.
    expect(max($size[0], $size[1]))->toBe(128);
});

it('keeps a gif as it arrived, so it goes on moving', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'dansje',
        'image' => UploadedFile::fake()->image('dansje.gif', 200, 200),
    ]);

    $emoji = CustomEmoji::firstOrFail();

    expect($emoji->mime)->toBe('image/gif')
        ->and($emoji->path)->toEndWith('.gif');
});

it('lower cases the name, so one emoji cannot arrive twice', function () {
    [$admin, $workspace] = emojiAdmin();

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'ShipIt',
        'image' => UploadedFile::fake()->image('logo.png'),
    ]);

    expect(CustomEmoji::firstOrFail()->name)->toBe('shipit');

    actingAs($admin)
        ->post(route('workspace.emoji.store'), [
            'name' => 'SHIPIT',
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertSessionHasErrors('name');

    expect($workspace->customEmoji()->count())->toBe(1);
});

it('refuses a name nobody could type between colons', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)
        ->post(route('workspace.emoji.store'), [
            'name' => 'ship it!',
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertSessionHasErrors('name');
});

it('refuses an svg, which is a script in a costume', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)
        ->post(route('workspace.emoji.store'), [
            'name' => 'logo',
            'image' => UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
        ])
        ->assertSessionHasErrors('image');
});

it('lets nobody but a manager add one', function () {
    Storage::fake('local');

    $member = User::factory()->create();
    workspaceWithMember($member, SystemRole::Member);

    actingAs($member)
        ->post(route('workspace.emoji.store'), [
            'name' => 'shipit',
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertForbidden();

    expect(CustomEmoji::count())->toBe(0);
});

it('takes one away, file and all', function () {
    [$admin] = emojiAdmin();

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'shipit',
        'image' => UploadedFile::fake()->image('logo.png'),
    ]);

    $emoji = CustomEmoji::firstOrFail();

    actingAs($admin)
        ->delete(route('workspace.emoji.destroy', $emoji))
        ->assertRedirect();

    expect(CustomEmoji::count())->toBe(0)
        ->and(Storage::disk('local')->exists($emoji->path))->toBeFalse();
});

it('will not let one workspace delete another workspace\'s emoji', function () {
    [$admin] = emojiAdmin();

    $elsewhere = CustomEmoji::factory()->create();

    actingAs($admin)
        ->delete(route('workspace.emoji.destroy', $elsewhere))
        ->assertNotFound();

    expect(CustomEmoji::whereKey($elsewhere->id)->exists())->toBeTrue();
});

it('hands the picture to a member and to nobody else', function () {
    [$admin, $workspace] = emojiAdmin();

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'shipit',
        'image' => UploadedFile::fake()->image('logo.png'),
    ]);

    $emoji = CustomEmoji::firstOrFail();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);

    actingAs($colleague)->get(route('custom-emoji.show', $emoji))->assertOk();

    /*
     * Signed in, but not here. A private workspace's pictures are not general
     * reading for everybody who happens to have an account.
     */
    actingAs(User::factory()->create())
        ->get(route('custom-emoji.show', $emoji))
        ->assertNotFound();
});

it('lets somebody react with an emoji this workspace has', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    CustomEmoji::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'shipit',
    ]);

    actingAs($user)
        ->post(route('chat.messages.reactions.store', [$workspace, $channel, $message]), [
            'emoji' => ':shipit:',
        ])
        ->assertRedirect();

    expect($message->reactions()->pluck('emoji')->all())->toBe([':shipit:']);
});

it('refuses a shortcode from another workspace, and any other word', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    // Real, and somewhere else. Stored here it would draw as bare text for
    // everybody in this room, because the picture is not theirs to fetch.
    CustomEmoji::factory()->create(['name' => 'elders']);

    $url = route('chat.messages.reactions.store', [$workspace, $channel, $message]);

    actingAs($user)->post($url, ['emoji' => ':elders:'])->assertSessionHasErrors('emoji');
    actingAs($user)->post($url, ['emoji' => 'lgtm'])->assertSessionHasErrors('emoji');

    expect($message->reactions()->count())->toBe(0);
});

it('sends the workspace its own emoji along with the chat page', function () {
    [$admin, $workspace] = emojiAdmin();

    $channel = channelWithMember($workspace, $admin);

    actingAs($admin)->post(route('workspace.emoji.store'), [
        'name' => 'shipit',
        'image' => UploadedFile::fake()->image('logo.png'),
    ]);

    actingAs($admin)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('workspace.customEmoji.0.name', 'shipit')
            ->has('workspace.customEmoji.0.url'));
});
