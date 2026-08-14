<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;

/**
 * Reads the pair back out of the command's own output rather than calling the
 * generator directly: what a person pastes into .env is the printed line, so
 * that is the thing worth checking.
 *
 * @return array{public: string, private: string}
 */
function generatedVapidKeys(): array
{
    expect(Artisan::call('webpush:vapid'))->toBe(0);

    $output = Artisan::output();

    preg_match('/^VAPID_PUBLIC_KEY=(\S+)$/m', $output, $public);
    preg_match('/^VAPID_PRIVATE_KEY=(\S+)$/m', $output, $private);

    expect($public[1] ?? null)->not->toBeNull()
        ->and($private[1] ?? null)->not->toBeNull();

    return ['public' => $public[1], 'private' => $private[1]];
}

/**
 * Base64url without padding, which is how both keys travel to the browser and
 * back — plain base64 would break on the + and / that a browser refuses.
 */
function decodeVapidKey(string $key): string
{
    return base64_decode(strtr($key, '-_', '+/'), true);
}

test('it prints a valid P-256 key pair', function () {
    ['public' => $public, 'private' => $private] = generatedVapidKeys();

    // An uncompressed P-256 point: the 0x04 marker plus a 32-byte X and Y.
    $point = decodeVapidKey($public);

    expect($point)->not->toBeFalse()
        ->and(strlen($point))->toBe(65)
        ->and(ord($point[0]))->toBe(4);

    // The private key is the scalar itself: 32 bytes, no framing.
    expect(strlen(decodeVapidKey($private)))->toBe(32);
});

test('it generates a different pair on every run', function () {
    expect(generatedVapidKeys())->not->toBe(generatedVapidKeys());
});

test('it leaves the environment file untouched', function () {
    $before = file_get_contents(base_path('.env'));

    generatedVapidKeys();

    expect(file_get_contents(base_path('.env')))->toBe($before);
})->skip(fn () => ! file_exists(base_path('.env')), 'Geen .env in deze omgeving.');

/*
 * Written through Env's own repository rather than putenv(), and restoring what
 * was there rather than clearing it. env() reads $_ENV before the process
 * environment, so on a machine whose .env actually holds a VAPID pair — which
 * is every machine where the feature has been set up — putenv() would be
 * silently outvoted and the test would assert against the developer's real
 * keys.
 */
test('the configuration reads the environment', function () {
    $repository = Env::getRepository();

    $restore = collect(['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT'])
        ->mapWithKeys(fn (string $key): array => [$key => $repository->get($key)]);

    $repository->set('VAPID_PUBLIC_KEY', 'public-half');
    $repository->set('VAPID_PRIVATE_KEY', 'private-half');
    $repository->set('VAPID_SUBJECT', 'mailto:beheerder@example.test');

    try {
        $services = require config_path('services.php');

        expect($services['webpush'])->toBe([
            'public_key' => 'public-half',
            'private_key' => 'private-half',
            'subject' => 'mailto:beheerder@example.test',
        ]);
    } finally {
        $restore->each(fn (?string $value, string $key) => $value === null
            ? $repository->clear($key)
            : $repository->set($key, $value));
    }
});
