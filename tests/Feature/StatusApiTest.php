<?php

use App\Enums\Availability;
use App\Models\ApiToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;

/**
 * A member and a token that speaks for them.
 *
 * @return array{0: User, 1: string}
 */
function tokenFor(User $user): array
{
    $token = new ApiToken(['user_id' => $user->id, 'name' => 'Script op mijn laptop']);
    $token->user_id = $user->id;
    $plain = $token->regenerateToken();
    $token->save();

    return [$user, $plain];
}

it('says what somebody is up to', function () {
    [$user, $token] = tokenFor(User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In een vergadering',
        'availability' => Availability::DoNotDisturb,
    ]));

    withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.v1.status.show'))
        ->assertOk()
        ->assertJsonPath('data.emoji', '📅')
        ->assertJsonPath('data.text', 'In een vergadering')
        ->assertJsonPath('data.availability', 'do-not-disturb');
});

it('sets a status and hands back what it became', function () {
    [$user, $token] = tokenFor(User::factory()->create());

    withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.v1.status.update'), [
            'emoji' => '🚗',
            'text' => 'Onderweg',
            'availability' => 'away',
        ])
        ->assertOk()
        ->assertJsonPath('data.text', 'Onderweg');

    expect($user->fresh()->status_text)->toBe('Onderweg');
});

it('answers in the language the caller asked for', function () {
    [, $token] = tokenFor(User::factory()->create([
        'availability' => Availability::DoNotDisturb,
    ]));

    /*
     * A script has no lang files of its own. The label comes back translated
     * because HandleLocale has already read the header — the same middleware
     * the screens go through.
     */
    withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept-Language', 'en')
        ->getJson(route('api.v1.status.show'))
        ->assertJsonPath('data.label', 'Do not disturb');

    withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Accept-Language', 'nl')
        ->getJson(route('api.v1.status.show'))
        ->assertJsonPath('data.label', 'Niet storen');
});

it('says whether a schedule or a person set it', function () {
    [, $token] = tokenFor(User::factory()->create());

    // What lets a script tell "my status changed" from "somebody overruled me".
    withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.v1.status.update'), ['availability' => 'available'])
        ->assertJsonPath('data.isManual', true);
});

it('refuses a caller with no token', function () {
    getJson(route('api.v1.status.show'))->assertUnauthorized();
});

it('refuses a token that was revoked', function () {
    [, $token] = tokenFor(User::factory()->create());

    ApiToken::query()->update(['revoked_at' => now()]);

    withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.v1.status.show'))
        ->assertUnauthorized();
});

it('refuses a status nobody has a name for', function () {
    [, $token] = tokenFor(User::factory()->create());

    withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.v1.status.update'), ['availability' => 'verzonnen'])
        ->assertUnprocessable();
});

it('notes that the token was used', function () {
    [, $token] = tokenFor(User::factory()->create());

    // What somebody looks at to decide whether a token is still in use.
    expect(ApiToken::sole()->last_used_at)->toBeNull();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.v1.status.show'))
        ->assertOk();

    expect(ApiToken::sole()->last_used_at)->not->toBeNull();
});

it('reaches nobody else', function () {
    [, $token] = tokenFor(User::factory()->create());

    $colleague = User::factory()->create(['status_text' => 'Van mij']);

    /*
     * There is no id in the path, and that is the design: a token identifies a
     * person, so there is nothing to point at somebody else with.
     */
    withHeader('Authorization', "Bearer {$token}")
        ->patchJson(route('api.v1.status.update'), ['availability' => 'away']);

    expect($colleague->fresh()->status_text)->toBe('Van mij');
});

it('tells somebody where to point a token', function () {
    $user = User::factory()->create();

    /*
     * The token screen was built for MCP; the same credential now opens the
     * plain API too. A credential whose address you cannot find is one nobody
     * uses, so both are handed over side by side.
     */
    actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('apiEndpoint', url('/api/v1'))
            ->has('endpoint'));
});
