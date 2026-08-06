<?php

use App\Actions\Huddles\IceServers;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * What a browser is told to reach the others with. The awkward part is the
 * relay credential: it has to be usable now and worthless later.
 */
it('says huddles are not set up when there is nowhere to look', function () {
    config(['huddles.stun_urls' => [], 'huddles.turn_urls' => []]);

    expect(app(IceServers::class)->configured())->toBeFalse()
        ->and(app(IceServers::class)->handle(User::factory()->create()))->toBe([]);
});

it('hands over the stun servers on their own when there is no relay', function () {
    config([
        'huddles.stun_urls' => ['stun:stun.example.com:3478'],
        'huddles.turn_urls' => [],
    ]);

    expect(app(IceServers::class)->handle(User::factory()->create()))
        ->toBe([['urls' => ['stun:stun.example.com:3478']]]);
});

it('signs a relay credential that expires and names who it was for', function () {
    config([
        'huddles.stun_urls' => ['stun:stun.example.com:3478'],
        'huddles.turn_urls' => ['turn:turn.example.com:3478'],
        'huddles.turn_secret' => 'geheim',
        'huddles.turn_ttl_minutes' => 120,
    ]);

    $user = User::factory()->create();
    $servers = app(IceServers::class)->handle($user);

    [$expiry, $id] = explode(':', $servers[1]['username']);

    expect((int) $id)->toBe($user->id)
        ->and((int) $expiry)->toBeGreaterThan(now()->timestamp)
        ->and((int) $expiry)->toBeLessThanOrEqual(now()->addMinutes(120)->timestamp)
        // What coturn checks: the name, signed with the shared secret.
        ->and($servers[1]['credential'])->toBe(
            base64_encode(hash_hmac('sha1', $servers[1]['username'], 'geheim', binary: true)),
        );
});

it('leaves the relay without a credential when no secret is shared with it', function () {
    config([
        'huddles.stun_urls' => ['stun:stun.example.com:3478'],
        'huddles.turn_urls' => ['turn:turn.example.com:3478'],
        'huddles.turn_secret' => null,
    ]);

    // A relay that wants a plain username and password of its own, or one that
    // is open. Either way this is not the place to invent one.
    expect(app(IceServers::class)->handle(User::factory()->create())[1])
        ->toBe(['urls' => ['turn:turn.example.com:3478']]);
});

it('keeps the servers away from somebody who may not join', function () {
    config(['huddles.stun_urls' => ['stun:stun.example.com:3478']]);

    [$member, , $workspace, $channel] = huddleFixture();
    $channel->forceFill(['archived_at' => now()])->save();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.iceServers', [])
            ->where('channel.canHuddle', false));
});

it('will not offer a huddle where nothing is configured to reach one with', function () {
    config(['huddles.stun_urls' => [], 'huddles.turn_urls' => []]);

    [$member, , $workspace, $channel] = huddleFixture();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canHuddle', false));
});
