<?php

use App\Models\ApiToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * A member with a usable token.
 *
 * @return array{0: User, 1: ApiToken, 2: string}
 */
function memberWithToken(): array
{
    $user = User::factory()->create();

    $record = new ApiToken(['user_id' => $user->id, 'name' => 'Claude op mijn laptop']);
    $plain = $record->regenerateToken();
    $record->save();

    return [$user, $record, $plain];
}

it('makes a token and shows it back', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Claude op mijn laptop'])
        ->assertRedirect();

    $token = ApiToken::sole();

    expect($token->user_id)->toBe($user->id)
        ->and($token->name)->toBe('Claude op mijn laptop')
        // Readable again on purpose: this is meant to be pasted into a config
        // file, and one you cannot read back is one you lose by closing the tab.
        ->and($token->plain())->toStartWith('mcp_');
});

it('never puts the hash in the page', function () {
    [$user] = memberWithToken();

    actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/api-tokens')
            ->has('tokens', 1)
            ->missing('tokens.0.token_hash'));
});

it('shows only your own tokens', function () {
    [, $theirs] = memberWithToken();

    actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tokens', 0));

    expect(ApiToken::whereKey($theirs->id)->exists())->toBeTrue();
});

/*
 * A personal token opens the API and nothing else. The MCP server moved to
 * OAuth — see McpOAuthTest, which holds the counterpart of every case here.
 */
it('signs an API request in as the member who owns the token', function () {
    [$user, , $plain] = memberWithToken();

    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertOk();

    expect(ApiToken::sole()->last_used_at)->not->toBeNull()
        ->and($user->fresh())->not->toBeNull();
});

it('refuses a request without a token', function () {
    getJson(route('api.v1.status.show'))->assertUnauthorized();
});

it('refuses a token nobody has', function () {
    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer mcp_verzonnen'])
        ->assertUnauthorized();
});

it('refuses a token that was withdrawn', function () {
    [$user, $record, $plain] = memberWithToken();

    actingAs($user)->delete(route('api-tokens.destroy', $record))->assertRedirect();

    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertUnauthorized();

    // Marked rather than deleted: the row is the record that it existed.
    expect($record->fresh()->isRevoked())->toBeTrue();
});

it('does not let somebody withdraw a token that is not theirs', function () {
    [, $theirs] = memberWithToken();

    actingAs(User::factory()->create())
        ->delete(route('api-tokens.destroy', $theirs))
        ->assertNotFound();

    expect($theirs->fresh()->isRevoked())->toBeFalse();
});

it('reads the header whatever case the client sends it in', function () {
    [, , $plain] = memberWithToken();

    getJson(route('api.v1.status.show'), ['Authorization' => 'bearer '.$plain])
        ->assertOk();
});

it('refuses an eleventh token, and says so in words', function () {
    $user = User::factory()->create();

    ApiToken::factory()->count(10)->create(['user_id' => $user->id]);

    /*
     * The message is asserted, not just the status. A missing key does not
     * throw — __() hands back the key itself — so a rename can leave the reader
     * staring at "chat.too_many_api_tokens" while every test still passes. That
     * is exactly what happened when this model lost its old name.
     */
    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Nummer elf'])
        ->assertStatus(422);

    expect(__('chat.too_many_api_tokens', ['count' => 10]))
        ->not->toContain('chat.too_many')
        ->toContain('10');
});

it('does not count a withdrawn token towards the limit', function () {
    $user = User::factory()->create();

    ApiToken::factory()->count(10)->create(['user_id' => $user->id, 'revoked_at' => now()]);

    // Otherwise somebody who cleaned up properly would be locked out by tokens
    // that no longer open anything.
    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Na het opruimen'])
        ->assertRedirect();

    expect(ApiToken::query()->whereNull('revoked_at')->count())->toBe(1);
});
