<?php

use App\Actions\Secrets\PruneSecretRequests;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\SecretValue;

use function Pest\Laravel\artisan;

/** A finished request with an answer sitting in it. */
function prunableRequest(array $state = []): SecretRequest
{
    $request = SecretRequest::factory()->create($state);
    $key = SecretRequestKey::factory()->create(['secret_request_id' => $request->id]);
    SecretValue::record($key, 'geheim', null);

    return $request->refresh();
}

it('leaves a request that is still taking answers', function () {
    $request = prunableRequest();

    artisan('secrets:prune')->assertSuccessful();

    expect(SecretRequest::find($request->id))->not->toBeNull();
});

/**
 * The grace period is short on purpose — shorter than a transfer's. A transfer
 * cleared too early costs an upload; a secret kept too long costs a password.
 */
it('leaves a request that only just expired', function () {
    $request = prunableRequest(['expires_at' => now()->subHours(6)]);

    artisan('secrets:prune')->assertSuccessful();

    expect(SecretRequest::find($request->id))->not->toBeNull();
});

it('clears an expired request and the values with it', function () {
    $request = prunableRequest([
        'expires_at' => now()->subDays(PruneSecretRequests::GRACE_DAYS + 1),
    ]);

    artisan('secrets:prune')
        ->expectsOutputToContain('1 verzoek opgeruimd.')
        ->assertSuccessful();

    expect(SecretRequest::find($request->id))->toBeNull()
        ->and(SecretRequestKey::count())->toBe(0)
        // The encrypted values are the whole reason this command exists.
        ->and(SecretValue::count())->toBe(0);
});

it('clears a withdrawn request once it has been withdrawn long enough', function () {
    $request = prunableRequest([
        'revoked_at' => now()->subDays(PruneSecretRequests::GRACE_DAYS + 1),
        'expires_at' => now()->addYear(),
    ]);

    artisan('secrets:prune')->assertSuccessful();

    expect(SecretRequest::find($request->id))->toBeNull()
        ->and(SecretValue::count())->toBe(0);
});

it('says so plainly when there is nothing to do', function () {
    artisan('secrets:prune')
        ->expectsOutputToContain('Niets om op te ruimen.')
        ->assertSuccessful();
});

it('clears the moment a request has been finished long enough', function () {
    $request = prunableRequest(['expires_at' => now()->subHour()]);

    artisan('secrets:prune')->assertSuccessful();
    expect(SecretRequest::find($request->id))->not->toBeNull();

    $this->travelTo(now()->addDays(PruneSecretRequests::GRACE_DAYS + 1));

    artisan('secrets:prune')->assertSuccessful();
    expect(SecretRequest::find($request->id))->toBeNull();
});

it('counts several at once', function () {
    SecretRequest::factory()->count(3)->create([
        'expires_at' => now()->subDays(PruneSecretRequests::GRACE_DAYS + 1),
    ]);
    SecretRequest::factory()->create();

    artisan('secrets:prune')
        ->expectsOutputToContain('3 verzoeken opgeruimd.')
        ->assertSuccessful();

    expect(SecretRequest::count())->toBe(1);
});
