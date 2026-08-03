<?php

use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\SecretValue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;

/**
 * A request with two keys, one of them already answered.
 *
 * @return array{0: SecretRequest, 1: SecretRequestKey, 2: SecretRequestKey}
 */
function secretRequestWithKeys(array $state = []): array
{
    $request = SecretRequest::factory()->create($state);

    $password = SecretRequestKey::factory()->create([
        'secret_request_id' => $request->id,
        'name' => 'DB_PASSWORD',
        'position' => 0,
    ]);
    $token = SecretRequestKey::factory()->create([
        'secret_request_id' => $request->id,
        'name' => 'API_TOKEN',
        'position' => 1,
    ]);

    return [$request->refresh(), $password, $token];
}

it('never keeps an answer in the clear', function () {
    [, $key] = secretRequestWithKeys();

    SecretValue::record($key, 'hunter2-en-nog-wat', null);

    // Read straight off the table, so nothing the model does can flatter it.
    $stored = DB::table('secret_values')->value('value');

    expect($stored)->not->toContain('hunter2')
        ->and(Crypt::decryptString($stored))->toBe('hunter2-en-nog-wat');
});

/**
 * The failure this model exists to prevent is not a broken cipher but a value
 * ending up in a payload nobody meant it to be in.
 */
it('keeps the value out of anything that serialises the model', function () {
    [, $key] = secretRequestWithKeys();

    $value = SecretValue::record($key, 'geheim', null);

    expect($value->toArray())->not->toHaveKey('value')
        ->and(json_encode($value))->not->toContain('geheim');
});

it('hands the plaintext back only when asked outright', function () {
    [, $key] = secretRequestWithKeys();
    $user = User::factory()->create();

    $value = SecretValue::record($key, 'wachtwoord-123', $user);

    expect($value->reveal())->toBe('wachtwoord-123')
        ->and($value->filled_by)->toBe($user->id)
        ->and($value->filled_at)->not->toBeNull();
});

/** Somebody has to be able to see that the value came back out at some point. */
it('records the first time it was read, and only the first', function () {
    [, $key] = secretRequestWithKeys();

    $value = SecretValue::record($key, 'geheim', null);
    expect($value->revealed_at)->toBeNull();

    $value->reveal();
    $first = $value->refresh()->revealed_at;

    expect($first)->not->toBeNull();

    $this->travelTo(now()->addHour());
    $value->reveal();

    expect($value->refresh()->revealed_at->equalTo($first))->toBeTrue();
});

/**
 * The promise is "fill it in once". A promise kept only by a check in
 * application code is one that two browser tabs can break, so the database
 * keeps it.
 */
it('refuses a second answer to the same key', function () {
    [, $key] = secretRequestWithKeys();

    SecretValue::record($key, 'eerste', null);

    /*
     * The refusal is the assertion, and nothing is asserted after it: Postgres
     * marks the surrounding transaction as aborted once a constraint fires, so
     * any query that followed would fail for that reason rather than tell us
     * anything about the row.
     */
    expect(fn () => SecretValue::record($key, 'tweede', null))
        ->toThrow(QueryException::class);
});

it('refuses the same key twice in one request', function () {
    [$request] = secretRequestWithKeys();

    expect(fn () => SecretRequestKey::factory()->create([
        'secret_request_id' => $request->id,
        'name' => 'DB_PASSWORD',
    ]))->toThrow(QueryException::class);
});

it('keeps the keys in the order they were asked for', function () {
    [$request] = secretRequestWithKeys();

    expect($request->keys->pluck('name')->all())
        ->toBe(['DB_PASSWORD', 'API_TOKEN']);
});

it('counts the answers across every key', function () {
    [$request, $password] = secretRequestWithKeys();

    expect($request->values()->count())->toBe(0);

    SecretValue::record($password, 'geheim', null);

    expect($request->values()->count())->toBe(1)
        ->and($password->refresh()->isAnswered())->toBeTrue();
});

it('stops taking answers once it has expired or been withdrawn', function (array $state) {
    [$request] = secretRequestWithKeys($state);

    expect($request->isOpen())->toBeFalse();
})->with([
    'expired' => [['expires_at' => now()->subDay()]],
    'withdrawn' => [['revoked_at' => now()->subHour()]],
]);

/**
 * Being fully answered is being finished, not being broken — the channel has to
 * be able to say the difference.
 */
it('stays open once every key has been answered', function () {
    [$request, $password, $token] = secretRequestWithKeys();

    SecretValue::record($password, 'een', null);
    SecretValue::record($token, 'twee', null);

    expect($request->isOpen())->toBeTrue();
});

it('finds the open ones in SQL as it does in PHP', function () {
    $open = SecretRequest::factory()->create();
    SecretRequest::factory()->expired()->create();
    SecretRequest::factory()->revoked()->create();

    expect(SecretRequest::open()->pluck('id')->all())->toBe([$open->id]);
});

/** The answers must not outlive the question they belong to. */
it('takes its keys and their answers with it', function () {
    [$request, $password] = secretRequestWithKeys();
    SecretValue::record($password, 'geheim', null);

    $request->delete();

    expect(SecretRequestKey::count())->toBe(0)
        ->and(SecretValue::count())->toBe(0);
});

it('goes when its workspace goes', function () {
    $workspace = Workspace::factory()->create();
    secretRequestWithKeys(['workspace_id' => $workspace->id]);

    $workspace->delete();

    expect(SecretRequest::count())->toBe(0)
        ->and(SecretValue::count())->toBe(0);
});

/**
 * A secret nobody can read is only a liability, so it goes with the person who
 * was going to read it — the opposite of a transfer, which outlives its sender.
 */
it('goes when the person who asked for it goes', function () {
    $requester = User::factory()->create();
    secretRequestWithKeys(['created_by' => $requester->id]);

    $requester->delete();

    expect(SecretRequest::count())->toBe(0);
});
