<?php

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiTokenContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

/**
 * A stand-in for the endpoints that will ask for a scope.
 *
 * Registered in the test rather than pointed at a real contract route, because
 * what is under test is the door and not the room behind it — and the room is
 * being built in parallel. It answers with what a controller reads off the
 * request, which is the other half of the contract this feature offers.
 */
function scopedRoute(string $scope = ApiToken::SCOPE_CONTRACTS): string
{
    Route::middleware(['api', 'api.token', "api.scope:{$scope}"])
        ->get('/api/v1/test-scoped', fn (Request $request) => response()->json([
            'workspace' => ApiTokenContext::workspace($request)?->id,
            'token' => ApiTokenContext::token($request)?->id,
        ]))
        ->name('api.v1.test.scoped');

    return '/api/v1/test-scoped';
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

/*
 * Narrowing a token: one workspace, and the scopes it was granted. Everything
 * above this line is about a token that was given neither, which is what every
 * token minted before this feature existed looks like.
 */
it('leaves a token without a workspace speaking for the member everywhere', function () {
    [$user, $record, $plain] = memberWithToken();

    workspaceWithMember($user);
    workspaceWithMember($user);

    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertOk();

    expect($record->fresh()->workspace_id)->toBeNull()
        ->and($record->fresh()->scopes)->toBeNull();
});

it('carries the workspace a token was pinned to through to the endpoint', function () {
    [$user, $record, $plain] = memberWithToken();

    $workspace = workspaceWithMember($user);
    $record->forceFill([
        'workspace_id' => $workspace->id,
        'scopes' => [ApiToken::SCOPE_CONTRACTS],
    ])->save();

    getJson(scopedRoute(), ['Authorization' => 'Bearer '.$plain])
        ->assertOk()
        ->assertJsonPath('workspace', $workspace->id)
        ->assertJsonPath('token', $record->id);
});

it('hands an unpinned token through as no workspace at all', function () {
    [, , $plain] = memberWithToken();

    ApiToken::sole()->forceFill(['scopes' => [ApiToken::SCOPE_CONTRACTS]])->save();

    // Null rather than a guess. An endpoint that needs one workspace has to ask
    // for it; silently picking the member's first would file a contract in a
    // workspace nobody named.
    getJson(scopedRoute(), ['Authorization' => 'Bearer '.$plain])
        ->assertOk()
        ->assertJsonPath('workspace', null);
});

it('refuses a token whose member has left the workspace it was made for', function () {
    [$user, $record, $plain] = memberWithToken();

    $workspace = workspaceWithMember($user);
    $record->forceFill(['workspace_id' => $workspace->id])->save();

    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertOk();

    $workspace->members()->detach($user->id);

    /*
     * 401 and not 403: the credential itself no longer resolves to anybody who
     * may use it, and there is nothing the caller can send along to fix that.
     */
    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertUnauthorized();
});

it('refuses a token for a workspace its member was never in', function () {
    [, $record, $plain] = memberWithToken();

    $record->forceFill(['workspace_id' => Workspace::factory()->create()->id])->save();

    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertUnauthorized();
});

it('refuses a scoped endpoint to a token that was granted nothing', function () {
    [, , $plain] = memberWithToken();

    getJson(scopedRoute(), ['Authorization' => 'Bearer '.$plain])
        ->assertForbidden()
        ->assertJsonPath('error', __('mcp.token.scope_missing', ['scope' => 'contracts']));

    // A null scopes column is not "all of them"; see ApiToken::allows().
    expect(ApiToken::sole()->allows(ApiToken::SCOPE_CONTRACTS))->toBeFalse();
});

it('refuses a scoped endpoint to a token granted a different scope', function () {
    [, $record, $plain] = memberWithToken();

    $record->forceFill(['scopes' => ['iets-anders']])->save();

    getJson(scopedRoute(), ['Authorization' => 'Bearer '.$plain])
        ->assertForbidden();
});

it('leaves the endpoints that ask for no scope open to every token', function () {
    [, , $plain] = memberWithToken();

    // The whole point of the null default: a token pasted into a config file
    // last month keeps working on the routes it was made for.
    getJson(route('api.v1.channels.index'), ['Authorization' => 'Bearer '.$plain])
        ->assertOk();
});

it('makes a token for one workspace with the scopes that were ticked', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Contractenrobot',
            'workspace_id' => $workspace->id,
            'scopes' => ['contracts'],
        ])
        ->assertRedirect();

    $token = ApiToken::sole();

    expect($token->workspace_id)->toBe($workspace->id)
        ->and($token->allows(ApiToken::SCOPE_CONTRACTS))->toBeTrue();
});

it('refuses to pin a token to a workspace the member is not in', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), [
            'name' => 'Van iemand anders',
            'workspace_id' => Workspace::factory()->create()->id,
        ])
        ->assertSessionHasErrors('workspace_id');

    expect(ApiToken::count())->toBe(0);
});

it('refuses a scope nobody has heard of', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Alles', 'scopes' => ['alles']])
        ->assertSessionHasErrors('scopes.0');
});

it('stores nothing ticked as no scopes rather than as an empty list', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Gewoon een token'])
        ->assertRedirect();

    // Both refuse every scope; null is the one that says it was never asked.
    expect(ApiToken::sole()->scopes)->toBeNull();
});

it('shows what each token reaches beside it', function () {
    [$user, $record] = memberWithToken();

    $workspace = workspaceWithMember($user);
    $record->forceFill([
        'workspace_id' => $workspace->id,
        'scopes' => [ApiToken::SCOPE_CONTRACTS],
    ])->save();

    actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tokens.0.workspace', $workspace->name)
            ->where('tokens.0.scopes', ['contracts'])
            ->has('workspaces', 1)
            ->where('scopes', ['contracts'])
            ->etc());
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
