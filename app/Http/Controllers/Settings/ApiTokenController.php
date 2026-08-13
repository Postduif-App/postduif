<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tokens a member handed to an AI client.
 *
 * A member's own screen rather than a workspace one: a token acts as the
 * person, so it is theirs to make and theirs to withdraw — and nobody else's
 * to see. It stays a personal screen even now that a token can be pinned to one
 * workspace, because pinning it narrows what the member reaches through it and
 * grants the workspace nothing it did not already have.
 */
class ApiTokenController extends Controller
{
    /** Enough for a laptop, a desktop and a spare. */
    private const MAX_TOKENS = 10;

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/api-tokens', [
            'endpoint' => url('/mcp/chat'),

            /*
             * The same token opens the plain HTTP API — see the note on
             * ApiToken's name, which no longer says what it does. Handed over
             * because a credential you cannot find the address for is a
             * credential nobody uses.
             */
            'apiEndpoint' => url('/api/v1'),

            /*
             * The workspaces to choose between, and every one of them — not
             * only the ones with a feature switched on. What a token may do
             * inside one is still decided by the policies at the moment it
             * calls, so a list filtered here would only mean somebody could not
             * mint a token for a workspace that is about to have contracts
             * turned on.
             */
            'workspaces' => $user->workspaces
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ])
                ->values()
                ->all(),

            'scopes' => ApiToken::SCOPES,
            'tokens' => $this->tokensFor($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],

            /*
             * The workspace has to be one of theirs, checked here as well as in
             * the middleware on every later request. Belt and braces on
             * purpose: this stops somebody minting a token for a workspace they
             * have never been in, which the middleware would refuse forever
             * afterwards without ever saying why.
             */
            'workspace_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_user', 'workspace_id')->where('user_id', $user->id),
            ],

            'scopes' => ['array'],
            'scopes.*' => ['string', Rule::in(ApiToken::SCOPES)],
        ]);

        abort_if(
            ApiToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count() >= self::MAX_TOKENS,
            422,
            __('chat.too_many_api_tokens', ['count' => self::MAX_TOKENS]),
        );

        $token = new ApiToken([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'workspace_id' => $validated['workspace_id'] ?? null,

            /*
             * Nothing ticked is stored as null rather than as an empty list.
             * Both refuse every scope, and null is the one that says "this
             * token was never given any" — the same thing every token minted
             * before this screen existed says.
             */
            'scopes' => $validated['scopes'] ?? null,
        ]);
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
            // The workspace is drawn beside every row, so it is fetched with
            // them rather than one query per token.
            ->with('workspace:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,

                /*
                 * Named rather than shown as an id. This is the line somebody
                 * reads to decide whether the token in their config file is the
                 * narrow one they meant to use.
                 */
                'workspace' => $token->workspace?->name,
                'scopes' => $token->scopes ?? [],
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
