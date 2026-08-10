<?php

namespace App\Support;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Pusher\Pusher;

/**
 * Who is actually here, asked of the websocket server rather than the database.
 *
 * Nothing in Postgres knows this. A session row says somebody signed in at some
 * point and would still say it with the laptop shut; the only thing that knows
 * a browser is on the other end of an open socket right now is Reverb, so this
 * asks Reverb.
 *
 * Two numbers, because they are two different questions and the difference
 * matters when reading them. Connections counts sockets — one person with the
 * chat open on a laptop and a phone is two. People counts the members Reverb
 * has in the workspace presence channels, each one once, which is the number
 * somebody means when they ask how many are online.
 *
 * The presence channels are the right place to ask: use-workspace-presence
 * subscribes every open workspace to `presence-chat.workspace.{slug}`, so
 * anybody with the application open is in exactly one of them per workspace,
 * under their account id. Channel-level presence would miss whoever is sitting
 * in a channel this workspace does not have open.
 */
class SocketPresence
{
    /**
     * Every workspace presence channel starts with this, and nothing else does
     * — the per-channel rosters are `presence-chat.channel.{id}` and would
     * otherwise be counted as well, which would put anybody reading a channel
     * into the total twice.
     */
    private const WORKSPACE_PREFIX = 'presence-chat.workspace.';

    /**
     * Null when the server cannot be reached, which is a different answer from
     * zero and has to stay one: nobody online and no server running look the
     * same in a count and could not be further apart to whoever is reading.
     *
     * @return array{connections: int, people: int}|null
     */
    public function snapshot(): ?array
    {
        $connections = $this->get('/connections');

        if ($connections === null) {
            return null;
        }

        return [
            'connections' => (int) ($connections['connections'] ?? 0),
            'people' => $this->people(),
        ];
    }

    /**
     * The distinct accounts across every workspace presence channel.
     *
     * A roster per channel rather than the `user_count` the channel listing can
     * hand back in one request: somebody who belongs to two workspaces has the
     * application open on both and would be counted twice by a sum. The ids are
     * what makes them one person, so the ids are what is asked for.
     *
     * A request per live workspace is more than one request, and that is the
     * price. Only workspaces with somebody in them appear in the listing at
     * all, so this is bounded by how busy the platform is rather than by how
     * many workspaces it has — and it runs when a human types `about`.
     */
    private function people(): int
    {
        $channels = $this->get('/channels', ['filter_by_prefix' => self::WORKSPACE_PREFIX]) ?? [];

        $people = [];

        foreach (array_keys((array) ($channels['channels'] ?? [])) as $channel) {
            $roster = $this->get('/channels/'.$channel.'/users') ?? [];

            foreach ((array) ($roster['users'] ?? []) as $user) {
                $id = (string) ($user['id'] ?? '');

                if ($id !== '') {
                    $people[$id] = true;
                }
            }
        }

        return count($people);
    }

    /**
     * A signed read of Reverb's Pusher-compatible HTTP API.
     *
     * Signed with the package's own helper rather than a hand-rolled HMAC —
     * getting the string-to-sign wrong is the kind of mistake that produces a
     * 401 nobody can read — but sent with the Http facade, so a test can fake
     * the server and so the timeouts below exist at all. Pusher's own client
     * would drag its Guzzle instance and its default patience along with it.
     *
     * Short and deliberately so: `about` is a screen somebody is waiting on,
     * and a websocket server that has not answered in two seconds has answered.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query = []): ?array
    {
        /** @var array{key: string, secret: string, app_id: string, options: array<string, mixed>} $config */
        $config = config('broadcasting.connections.reverb');

        $path = '/apps/'.$config['app_id'].$path;

        $params = Pusher::build_auth_query_params(
            $config['key'],
            $config['secret'],
            'GET',
            $path,
            $query,
        );

        /*
         * HttpClientException rather than ConnectionException, so this covers
         * both halves of "no answer": nothing listening on the port, and
         * something listening that says no. The second one is the interesting
         * case — a wrong REVERB_APP_SECRET in the app's env gets a 401 back and
         * would otherwise take the whole about command down with it, which is a
         * poor way to learn that two config values have drifted apart.
         */
        try {
            $response = Http::connectTimeout(1)
                ->timeout(2)
                ->get($this->origin($config['options']).$path, $params);
        } catch (HttpClientException) {
            return null;
        }

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Where this application dials Reverb, which is not always where a browser
     * does — see the Docker setup, where the app talks to a service name and
     * the bundle talks to localhost. The broadcast connection's options are the
     * app's side of that, and the app's side is the one making this request.
     *
     * @param  array<string, mixed>  $options
     */
    private function origin(array $options): string
    {
        return ($options['scheme'] ?? 'http').
            '://'.($options['host'] ?? '127.0.0.1').
            ':'.($options['port'] ?? 8080).
            rtrim('/'.ltrim((string) ($options['path'] ?? ''), '/'), '/');
    }
}
