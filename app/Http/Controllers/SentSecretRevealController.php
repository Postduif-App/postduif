<?php

namespace App\Http\Controllers;

use App\Actions\Secrets\RevealSentSecret;
use App\Models\SentSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Picking up a secret somebody sent you.
 *
 * Outside every auth group, like the public transfer page and unlike the form
 * for answering a secret request. That is not a relaxation: the link carries the
 * key, so holding it is the credential, and there is no account for the server
 * to check it against. The recipient may be a customer who will never have one.
 */
class SentSecretRevealController extends Controller
{
    public function __construct(private readonly RevealSentSecret $revealSentSecret) {}

    public function show(SentSecret $sentSecret): Response
    {
        $sentSecret->load(['sender', 'recipient']);

        return Inertia::render('secrets/reveal', [
            'secret' => [
                'id' => $sentSecret->id,
                'label' => $sentSecret->label,
                'senderName' => $sentSecret->sender->name,
                'recipientName' => $sentSecret->recipient->name,
                'expiresAt' => $sentSecret->expires_at,
                'revealedAt' => $sentSecret->revealed_at,
                /*
                 * One word rather than three booleans: the page says a single
                 * sentence, and working out which one is not a thing to do
                 * twice. "Al opgehaald" is a mededeling, not an error — see the
                 * brief.
                 */
                'state' => $sentSecret->state(),
                // Whether to draw the password field. Never the hash, and never
                // whether a given guess would work.
                'needsPassword' => $sentSecret->needsPassword(),
            ],
        ]);
    }

    /**
     * Hand it over, once.
     *
     * JSON rather than an Inertia redirect, and that is deliberate: the answer
     * is ciphertext the browser has to decrypt before anything can be shown, and
     * an Inertia visit would put it through the page props — where it would sit
     * in the history entry, in the back-forward cache, and in any devtools tab
     * that happened to be open. This response is read once by fetch and never
     * stored.
     *
     * Throttled hard by the route, which is what makes the optional password
     * worth having at all.
     */
    public function reveal(Request $request, SentSecret $sentSecret): JsonResponse
    {
        $result = $this->revealSentSecret->handle(
            $sentSecret,
            $request->input('password'),
        );

        if (! $result['ok']) {
            /*
             * 422 for a wrong password, 410 for anything else. The difference
             * matters to the screen: one invites another try, the others are the
             * end of the story and it must not offer a button that cannot work.
             */
            return response()->json(
                ['reason' => $result['reason']],
                $result['reason'] === 'password' ? 422 : 410,
            );
        }

        return response()->json([
            'ciphertext' => $result['ciphertext'],
            'iv' => $result['iv'],
        ])
            /*
             * Belt and braces on a response that is already once-only: nothing
             * in between should be keeping a copy, and a shared proxy caching
             * this would hand the secret to whoever asked next.
             */
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
