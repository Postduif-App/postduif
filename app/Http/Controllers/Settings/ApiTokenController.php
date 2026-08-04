<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
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
class ApiTokenController extends Controller
{
    /** Enough for a laptop, a desktop and a spare. */
    private const MAX_TOKENS = 10;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/api-tokens', [
            'endpoint' => url('/mcp/chat'),

            /*
             * The same token opens the plain HTTP API — see the note on
             * ApiToken's name, which no longer says what it does. Handed over
             * because a credential you cannot find the address for is a
             * credential nobody uses.
             */
            'apiEndpoint' => url('/api/v1'),
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
            ApiToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count() >= self::MAX_TOKENS,
            422,
            __('chat.too_many_api_tokens', ['count' => self::MAX_TOKENS]),
        );

        $token = new ApiToken(['user_id' => $user->id, 'name' => $validated['name']]);
        $token->regenerateToken();
        $token->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('settings.api_tokens.created_toast'),
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
    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->user_id === $request->user()->id, 404);

        if (! $apiToken->isRevoked()) {
            $apiToken->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.api_tokens.revoked_toast')]);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokensFor(Request $request): array
    {
        return ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token): array => [
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
