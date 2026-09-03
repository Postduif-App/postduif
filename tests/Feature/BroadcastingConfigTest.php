<?php

/**
 * Guzzle's `base_uri` resolution follows RFC 3986: a base path that does not
 * end in "/" loses its last segment when a relative path is merged in. The
 * Pusher SDK feeds this "path" straight into Guzzle as its base_uri, so
 * without a trailing slash "/reverb" + "apps/{id}/events" resolves to
 * "/apps/{id}/events" — the "reverb" prefix silently vanishes and requests
 * miss nginx's proxy, landing on this application's own 404 page instead.
 *
 * Reads config/broadcasting.php directly rather than through config() so the
 * env value is picked up fresh, unaffected by whatever already booted.
 *
 * Written to $_SERVER as well as $_ENV and putenv(): env() reads $_SERVER
 * first, so under the parallel runner — whose worker processes inherit
 * whatever the orchestrator already loaded from .env into the real process
 * environment — a $_SERVER entry from that inheritance would otherwise win
 * over these two, silently ignoring the override.
 */
it('keeps a trailing slash on the reverb broadcasting path', function () {
    putenv('REVERB_SERVER_PATH=reverb');
    $_ENV['REVERB_SERVER_PATH'] = 'reverb';
    $_SERVER['REVERB_SERVER_PATH'] = 'reverb';

    $config = require base_path('config/broadcasting.php');

    expect($config['connections']['reverb']['options']['path'])->toBe('/reverb/');

    putenv('REVERB_SERVER_PATH');
    unset($_ENV['REVERB_SERVER_PATH'], $_SERVER['REVERB_SERVER_PATH']);
});

it('leaves the reverb broadcasting path empty when no prefix is configured', function () {
    putenv('REVERB_SERVER_PATH');
    unset($_ENV['REVERB_SERVER_PATH'], $_SERVER['REVERB_SERVER_PATH']);

    $config = require base_path('config/broadcasting.php');

    expect($config['connections']['reverb']['options']['path'])->toBe('');
});
