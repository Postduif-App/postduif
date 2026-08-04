<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Two channels a member can see, each with a message saying the same word.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel}
 */
function searchFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $general = channelWithMember($workspace, $user);
    $general->forceFill(['name' => 'algemeen'])->save();

    $customer = channelWithMember($workspace, $user);
    $customer->forceFill(['name' => 'klant-24'])->save();

    foreach ([$general, $customer] as $channel) {
        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => 'De offerte staat klaar',
        ]);
    }

    return [$user, $workspace, $general, $customer];
}

it('searches everywhere when no channel is named', function () {
    [$user, $workspace] = searchFixture();

    actingAs($user);

    $results = getJson(route('chat.search', $workspace).'?q=offerte')
        ->assertOk()
        ->json('results');

    expect($results)->toHaveCount(2);
});

it('narrows to the channel that was named', function () {
    [$user, $workspace, $general] = searchFixture();

    actingAs($user);

    $results = getJson(route('chat.search', $workspace).'?q=offerte&in=algemeen')
        ->assertOk()
        ->json('results');

    expect($results)->toHaveCount(1)
        ->and($results[0]['channel']['id'])->toBe($general->id);
});

it('does not mind how the channel name was capitalised', function () {
    [$user, $workspace] = searchFixture();

    actingAs($user);

    // Somebody typing "in:Algemeen" means the same channel as "in:algemeen".
    expect(getJson(route('chat.search', $workspace).'?q=offerte&in=Algemeen')->json('results'))
        ->toHaveCount(1);
});

it('searches everything when the named channel does not exist', function () {
    [$user, $workspace] = searchFixture();

    actingAs($user);

    /*
     * Rather than refusing. A name that resolves to nothing is somebody
     * mistyping, and an empty result page with no explanation reads as "there
     * is nothing" rather than "there is no such channel".
     */
    expect(getJson(route('chat.search', $workspace).'?q=offerte&in=verzonnen')->json('results'))
        ->toHaveCount(2);
});

it('will not use a named channel to reach into one you cannot read', function () {
    [$user, $workspace] = searchFixture();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['joined_at' => now()]);

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
        'name' => 'directie',
    ]);
    $private->members()->attach($outsider->id, ['joined_at' => now()]);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $private->id,
        'user_id' => $user->id,
        'body' => 'De offerte staat klaar',
    ]);

    actingAs($user);

    // SearchMessages intersects the named channel with what this member may
    // read, so naming one is a narrowing and never a way in.
    expect(getJson(route('chat.search', $workspace).'?q=offerte&in='.$private->name)->json('results'))
        ->toHaveCount(0);
});

it('narrows to the person who wrote it', function () {
    [$user, $workspace, $general] = searchFixture();

    $colleague = User::factory()->create(['username' => 'fenna']);
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);
    $general->members()->attach($colleague->id, ['joined_at' => now()]);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $general->id,
        'user_id' => $colleague->id,
        'body' => 'De offerte staat klaar',
    ]);

    actingAs($user);

    expect(getJson(route('chat.search', $workspace).'?q=offerte&from=fenna')->json('results'))
        ->toHaveCount(1);
});

it('finds nothing for a handle nobody has', function () {
    [$user, $workspace] = searchFixture();

    actingAs($user);

    /*
     * Unlike an unknown channel, which falls back to searching everything. A
     * mistyped handle that quietly returned every colleague's messages would
     * look like a result rather than like a typo — and the reader would have
     * no way to tell.
     */
    expect(getJson(route('chat.search', $workspace).'?q=offerte&from=fena')->json('results'))
        ->toHaveCount(0);
});

it('does not reach outside the workspace with a handle', function () {
    [$user, $workspace] = searchFixture();

    User::factory()->create(['username' => 'buitenstaander']);

    actingAs($user);

    expect(getJson(route('chat.search', $workspace).'?q=offerte&from=buitenstaander')->json('results'))
        ->toHaveCount(0);
});

it('takes a channel and an author together', function () {
    [$user, $workspace, $general, $customer] = searchFixture();

    $colleague = User::factory()->create(['username' => 'fenna']);
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    foreach ([$general, $customer] as $channel) {
        $channel->members()->attach($colleague->id, ['joined_at' => now()]);

        Message::factory()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'user_id' => $colleague->id,
            'body' => 'De offerte staat klaar',
        ]);
    }

    actingAs($user);

    $results = getJson(route('chat.search', $workspace).'?q=offerte&in=algemeen&from=fenna')
        ->json('results');

    expect($results)->toHaveCount(1)
        ->and($results[0]['channel']['id'])->toBe($general->id);
});
