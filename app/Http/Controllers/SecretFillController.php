<?php

namespace App\Http\Controllers;

use App\Events\SecretRequestAnswered;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\SecretValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Answering a request for secrets.
 *
 * The whole of this controller is written around one sentence from the brief:
 * everybody else may fill it in once and then never see it again. So nothing
 * here ever sends a value back — not on the page, not in the response to the
 * very request that submitted it, not in a flash message. The only thing that
 * comes back is that it worked.
 */
class SecretFillController extends Controller
{
    public function show(Request $request, SecretRequest $secretRequest): Response|RedirectResponse
    {
        $this->authorize('viewForm', $secretRequest);

        /*
         * One link in the channel, two destinations. The person who asked never
         * fills their own form, so sending them there would be a dead end with
         * a "0 van 2 ingevuld" they cannot act on — they want what came in.
         *
         * Decided here rather than on the card, because this is the first point
         * where the viewer is known. The card is drawn once and broadcast to
         * everybody in the channel at the same time.
         */
        if ($request->user()->can('view', $secretRequest)) {
            return redirect()->route('secrets.answers', $secretRequest);
        }

        $secretRequest->load(['keys.value', 'requester', 'channel']);

        return Inertia::render('secrets/fill', [
            'request' => [
                'id' => $secretRequest->id,
                'title' => $secretRequest->title,
                'description' => $secretRequest->description,
                'requesterName' => $secretRequest->requester->name,
                'expiresAt' => $secretRequest->expires_at,
                'isOpen' => $secretRequest->isOpen(),
                'state' => match (true) {
                    $secretRequest->isRevoked() => 'revoked',
                    $secretRequest->hasExpired() => 'expired',
                    default => 'open',
                },
                'burnAfterReading' => $secretRequest->burn_after_reading,

                /*
                 * Whether each key has an answer — never the answer. Somebody
                 * arriving second has to be able to see there is nothing left
                 * for them to do, and that is the whole of what they get.
                 */
                'keys' => $secretRequest->keys->map(fn (SecretRequestKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'hint' => $key->hint,
                    'isAnswered' => $key->value !== null,
                ])->all(),
            ],
        ]);
    }

    /**
     * Take the answers.
     *
     * Two things are load-bearing here and both are easy to lose in a refactor:
     * the response carries nothing back, and the "once" is enforced by the
     * database inside a transaction rather than by a check beforehand. Two tabs
     * submitting together would otherwise both find the key empty and both
     * write — which is exactly the promise this feature makes.
     */
    public function store(Request $request, SecretRequest $secretRequest): RedirectResponse
    {
        $this->authorize('fill', $secretRequest);

        $validated = $request->validate([
            'values' => ['required', 'array', 'min:1'],
            'values.*' => ['nullable', 'string', 'max:4000'],
        ], [
            'values.required' => __('requests.secret.values_required'),
        ]);

        $answered = 0;

        DB::transaction(function () use ($secretRequest, $validated, $request, &$answered): void {
            /*
             * Locked in the order the keys were asked for. Without the lock two
             * submissions race between the "is it empty" check and the insert;
             * with it, the second waits and then finds the row taken.
             */
            $keys = $secretRequest->keys()
                ->whereDoesntHave('value')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($validated['values'] as $keyId => $plaintext) {
                $key = $keys->get((int) $keyId);

                // Silently skipped rather than refused: a key somebody else
                // answered while this form was open is not this person's
                // mistake, and telling them so after they typed a password is
                // an invitation to send it another way.
                if ($key === null || $plaintext === null || trim($plaintext) === '') {
                    continue;
                }

                SecretValue::record($key, $plaintext, $request->user());
                $answered++;
            }
        });

        /*
         * How many boxes were filled in, and nothing about what went in them —
         * the values are encrypted in the browser and this application could
         * not pass them on if a workflow asked. Only when something was
         * actually answered: a form submitted with every box empty is not a
         * handover.
         */
        if ($answered > 0) {
            SecretRequestAnswered::dispatch($secretRequest->id, $answered, $request->user()?->id);
        }

        /*
         * Nothing about what was submitted goes back. Not the values, and not a
         * summary that quotes them — a flash message lives in the session and
         * the session lives in a cookie store.
         */
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('flashes.secret.filled', $answered),
        ]);

        return back();
    }
}
