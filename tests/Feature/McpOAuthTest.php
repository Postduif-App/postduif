<?php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** A ping is enough: what is under test is getting past the door, not the room. */
function ping(array $headers = []): TestResponse
{
    return postJson('/mcp/chat', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'ping',
    ], $headers);
}

/**
 * The three things a client needs before it can ask for anything.
 *
 * A hosted client — the connectors in ChatGPT and Claude — is handed a URL and
 * nothing else. Everything it needs to know it reads here, which is why these
 * are asserted by hand rather than trusted to the package: a missing well-known
 * document is a client that cannot connect and cannot say why.
 */
it('says where the authorisation server is', function () {
    getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource', url('/'))
        ->assertJsonPath('authorization_servers.0', url('/'))
        ->assertJsonPath('scopes_supported.0', Registrar::OAUTH_SCOPE);
});

it('says what the authorisation server supports', function () {
    getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', url('/'))
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        // PKCE and nothing weaker. An MCP client is a public client: it cannot
        // keep a secret, so the code exchange has to be bound to the request
        // that started it.
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
});

it('lets a client nobody configured register itself', function () {
    $registered = postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])->assertCreated();

    /*
     * The point of dynamic registration: no shared secret is arranged in
     * advance and nobody here has to add a client by hand before somebody can
     * connect one.
     */
    expect($registered->json('client_id'))->not->toBeEmpty();
});

it('refuses a request with no grant at all', function () {
    ping()->assertUnauthorized();
});

it('lets a member in with a token they granted', function () {
    $user = User::factory()->create();

    Passport::actingAs($user, [Registrar::OAUTH_SCOPE]);

    ping()->assertOk();
});

/**
 * The counterpart of the ApiTokenTest cases that used to live on this endpoint.
 *
 * Written down rather than left implicit, because the personal token still
 * exists and still opens the REST API — so "it did not work here" is the only
 * thing standing between the two doors and somebody quietly reconnecting them.
 */
it('no longer opens the MCP server with a personal token', function () {
    $user = User::factory()->create();

    $record = new ApiToken(['user_id' => $user->id, 'name' => 'Claude op mijn laptop']);
    $plain = $record->regenerateToken();
    $record->save();

    ping(['Authorization' => 'Bearer '.$plain])->assertUnauthorized();

    // And the same token still opens the API it was meant for.
    getJson(route('api.v1.status.show'), ['Authorization' => 'Bearer '.$plain])
        ->assertOk();
});

it('asks the member out loud, in the house style, whose account is being handed over', function () {
    $user = User::factory()->create();

    $client = Client::create([
        'name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ]);

    $response = $this->actingAs($user)->get(route('passport.authorizations.authorize', [
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => Registrar::OAUTH_SCOPE,
        'state' => 'iets',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    expect($response->getContent())
        // Whose account, said out loud. The client put its own name in the
        // address bar; this page is the only thing that can say the rest.
        ->toContain($user->email)
        ->toContain('Claude')
        // And the brand, like every other screen somebody meets from outside.
        ->toContain('postduif')
        ->toContain('M30.5 7.6C35.6');
});
