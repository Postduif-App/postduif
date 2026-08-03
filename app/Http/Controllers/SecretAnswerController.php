<?php

namespace App\Http\Controllers;

use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one place an answer comes back out.
 *
 * Everything about this controller is arranged so that a value travels exactly
 * once, to exactly one person, at a moment they asked for. The list below never
 * carries a value — it says which keys have been answered and by whom — and the
 * value itself comes from reveal(), one key at a time, over XHR.
 *
 * Why not simply put them in the page props: an Inertia payload is embedded in
 * the HTML on a full page load and kept in the browser's history for a back
 * button to restore. That is a long life for a customer's database password,
 * and none of it is visible to the person who opened the page.
 */
class SecretAnswerController extends Controller
{
    public function index(SecretRequest $secretRequest): Response
    {
        $this->authorize('view', $secretRequest);

        $secretRequest->load(['keys.value.author', 'channel']);

        return Inertia::render('secrets/answers', [
            'request' => [
                'id' => $secretRequest->id,
                'title' => $secretRequest->title,
                'description' => $secretRequest->description,
                'expiresAt' => $secretRequest->expires_at,
                'burnAfterReading' => $secretRequest->burn_after_reading,
                'state' => match (true) {
                    $secretRequest->isRevoked() => 'revoked',
                    $secretRequest->hasExpired() => 'expired',
                    default => 'open',
                },
                'channelName' => (string) $secretRequest->channel->name,

                'keys' => $secretRequest->keys->map(fn (SecretRequestKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'hint' => $key->hint,
                    'isAnswered' => $key->value !== null,
                    // Who and when, which is what the requester needs to chase
                    // the ones still missing. Never what.
                    'filledBy' => $key->value?->author?->name,
                    'filledAt' => $key->value?->filled_at,
                    'revealedAt' => $key->value?->revealed_at,
                ])->all(),
            ],
        ]);
    }

    /**
     * Hand over one value.
     *
     * JSON rather than a redirect with a flash: a flash lives in the session,
     * the session lives in a cookie or a file, and it would be read back on the
     * next page load whether anybody wanted it or not. This response is
     * consumed by the script that asked for it and then it is gone.
     *
     * The no-store header is not decoration — without it a proxy or the browser
     * itself is entitled to keep the body around.
     */
    public function reveal(SecretRequest $secretRequest, SecretRequestKey $key): JsonResponse
    {
        $this->authorize('view', $secretRequest);

        abort_unless($key->secret_request_id === $secretRequest->id, 404);

        $value = $key->value;

        abort_if($value === null, 404);

        $plaintext = $value->reveal();

        /*
         * The sharpest setting, and why it is worth having: a password that is
         * going straight into a server is safest when it stops existing the
         * moment it has been read. The row goes with it — there is nothing left
         * to decrypt and nothing left to leak.
         */
        if ($secretRequest->burn_after_reading) {
            $value->delete();
        }

        return response()
            ->json([
                'value' => $plaintext,
                'burned' => $secretRequest->burn_after_reading,
            ])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
