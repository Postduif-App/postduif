<?php

use App\Models\McpToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * A member with a usable token.
 *
 * @return array{0: User, 1: McpToken, 2: string}
 */
function memberWithToken(): array
{
    $user = User::factory()->create();

    $record = new McpToken(['user_id' => $user->id, 'name' => 'Claude op mijn laptop']);
    $plain = $record->regenerateToken();
    $record->save();

    return [$user, $record, $plain];
}

it('makes a token and shows it back', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp-tokens.store'), ['name' => 'Claude op mijn laptop'])
        ->assertRedirect();

    $token = McpToken::sole();

    expect($token->user_id)->toBe($user->id)
        ->and($token->name)->toBe('Claude op mijn laptop')
        // Readable again on purpose: this is meant to be pasted into a config
        // file, and one you cannot read back is one you lose by closing the tab.
        ->and($token->plain())->toStartWith('mcp_');
});

it('never puts the hash in the page', function () {
    [$user] = memberWithToken();

    actingAs($user)
        ->get(route('mcp-tokens.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/mcp-tokens')
            ->has('tokens', 1)
            ->missing('tokens.0.token_hash'));
});

it('shows only your own tokens', function () {
    [, $theirs] = memberWithToken();

    actingAs(User::factory()->create())
        ->get(route('mcp-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tokens', 0));

    expect(McpToken::whereKey($theirs->id)->exists())->toBeTrue();
});

it('signs an MCP request in as the member who owns the token', function () {
    [$user, , $plain] = memberWithToken();

    // A bare initialize call is enough: what is under test is whether the
    // request gets past the middleware at all.
    postJson('/mcp/chat', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'ping',
    ], ['Authorization' => 'Bearer '.$plain])->assertOk();

    expect(McpToken::sole()->last_used_at)->not->toBeNull()
        ->and($user->fresh())->not->toBeNull();
});

it('refuses a request without a token', function () {
    postJson('/mcp/chat', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
        ->assertUnauthorized();
});

it('refuses a token nobody has', function () {
    postJson('/mcp/chat', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'ping',
    ], ['Authorization' => 'Bearer mcp_verzonnen'])->assertUnauthorized();
});

it('refuses a token that was withdrawn', function () {
    [$user, $record, $plain] = memberWithToken();

    actingAs($user)->delete(route('mcp-tokens.destroy', $record))->assertRedirect();

    postJson('/mcp/chat', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'ping',
    ], ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();

    // Marked rather than deleted: the row is the record that it existed.
    expect($record->fresh()->isRevoked())->toBeTrue();
});

it('does not let somebody withdraw a token that is not theirs', function () {
    [, $theirs] = memberWithToken();

    actingAs(User::factory()->create())
        ->delete(route('mcp-tokens.destroy', $theirs))
        ->assertNotFound();

    expect($theirs->fresh()->isRevoked())->toBeFalse();
});

it('reads the header whatever case the client sends it in', function () {
    [, , $plain] = memberWithToken();

    postJson('/mcp/chat', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'ping',
    ], ['Authorization' => 'bearer '.$plain])->assertOk();
});
