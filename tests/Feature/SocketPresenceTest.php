<?php

use App\Support\SocketPresence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('counts the sockets and the people behind them', function () {
    reverbIsUp(connections: 5, rosters: ['acme' => [1, 2, 3]]);

    expect(app(SocketPresence::class)->snapshot())
        ->toBe(['connections' => 5, 'people' => 3]);
});

it('counts somebody with two workspaces open as one person', function () {
    /*
     * Five sockets, four accounts, three people: the point of asking every
     * roster for ids rather than summing the counts Reverb would hand over in
     * one request.
     */
    reverbIsUp(connections: 5, rosters: [
        'acme' => [1, 2, 3],
        'globex' => [3],
    ]);

    expect(app(SocketPresence::class)->snapshot())
        ->toBe(['connections' => 5, 'people' => 3]);
});

it('ignores the per-channel rosters, which would count a reader twice', function () {
    reverbIsUp(connections: 2, rosters: ['acme' => [1, 2]]);

    app(SocketPresence::class)->snapshot();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/channels?')
        || str_contains($request->url(), 'filter_by_prefix=presence-chat.workspace.'));
});

it('signs its requests the way reverb expects', function () {
    reverbIsUp(connections: 0);

    app(SocketPresence::class)->snapshot();

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['auth_key'] === config('broadcasting.connections.reverb.key')
            && $query['auth_version'] === '1.0'
            && isset($query['auth_signature'], $query['auth_timestamp'])
            && str_contains($request->url(), '/apps/'.config('broadcasting.connections.reverb.app_id').'/');
    });
});

it('says nothing rather than zero when reverb is not answering', function () {
    reverbIsDown();

    /*
     * Null and not 0. Nobody online and no server running are the same number
     * and opposite situations.
     */
    expect(app(SocketPresence::class)->snapshot())->toBeNull();
});

it('survives a secret that has drifted apart from the server', function () {
    reverbIsDown(status: 401);

    expect(app(SocketPresence::class)->snapshot())->toBeNull();
});

it('survives a websocket server that is not there at all', function () {
    freshHttpClient();
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(app(SocketPresence::class)->snapshot())->toBeNull();
});
