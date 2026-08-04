<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tokens a member handed to an AI client.
 *
 * A member's own screen rather than a workspace one: a token acts as the
 * person, across every workspace they belong to, so it is theirs to make and
 * theirs to withdraw — and nobody else's to see.
 */
class McpTokenController extends Controller
{
    /** Enough for a laptop, a desktop and a spare. */
    private const MAX_TOKENS = 10;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/mcp-tokens', [
            'endpoint' => url('/mcp/chat'),
            'tokens' => $this->tokensFor($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        abort_if(
            McpToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count() >= self::MAX_TOKENS,
            422,
            'Je hebt al '.self::MAX_TOKENS.' actieve tokens.',
        );

        $token = new McpToken(['user_id' => $user->id, 'name' => $validated['name']]);
        $token->regenerateToken();
        $token->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Token aangemaakt. Plak hem in je MCP-client.',
        ]);

        return back();
    }

    /**
     * Withdraw it.
     *
     * Marked rather than deleted, like an invite link: the token may sit in a
     * config file somewhere, and the row is the record that it existed and
     * when it was last used — which is exactly what somebody wants to see
     * after they revoke one in a hurry.
     */
    public function destroy(Request $request, McpToken $mcpToken): RedirectResponse
    {
        abort_unless($mcpToken->user_id === $request->user()->id, 404);

        if (! $mcpToken->isRevoked()) {
            $mcpToken->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Token ingetrokken.']);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokensFor(Request $request): array
    {
        return McpToken::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (McpToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                // Shown again on purpose: this is meant to be pasted into a
                // config file, and one you cannot read back is one you lose by
                // closing the tab. Same trade the webhooks make.
                'token' => $token->plain(),
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'revokedAt' => $token->revoked_at?->toIso8601String(),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
