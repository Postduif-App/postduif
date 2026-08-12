<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\PresentContractForSigner;
use App\Actions\Contracts\SaveSignerDraft;
use App\Features\Contracts;
use App\Models\ContractSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Signing from outside.
 *
 * Outside auth, like picking up a transfer or filling in a shared form: the
 * person doing this may have no account and may never want one. The token in
 * the address is the whole permission.
 *
 * Where this deliberately differs from PublicFormController is what happens when
 * the link no longer works. A form answers 404 to everything, so that an old
 * address stops being evidence that anything is there — the right call for a
 * link that was shared with nobody in particular and could be guessed at.
 *
 * A contract is the other case entirely. This link was mailed to one named
 * person at one address by somebody who told them it was coming, so "dit is
 * ingetrokken" tells them nothing they could not already work out, and telling
 * them nothing at all is how somebody ends up ringing to ask why the link is
 * broken. Three states, three answers — see show().
 *
 * What stays strict is the lookup: by token, never by id, compared with
 * hash_equals, behind a throttle. An unknown token is a plain 404, because
 * there really is nothing there.
 */
class ContractSignController extends Controller
{
    public function show(string $token, PresentContractForSigner $present): Response
    {
        $signer = $this->signer($token);

        /*
         * Stamped on the way in, once.
         *
         * Worth having on its own, and it is the reason this is not done at
         * signing time: "hij heeft het niet eens geopend" and "hij heeft het
         * gezien en niets gedaan" are different conversations for the person
         * waiting, and only the first visit can tell them apart.
         */
        if ($signer->opened_at === null) {
            $signer->forceFill(['opened_at' => now()])->save();
        }

        return Inertia::render('contracts/sign', [
            'token' => $token,
            'state' => $this->state($signer),
            'contract' => $present->handle($signer),
            'documentUrl' => route('contracts.sign.document', $token),
        ]);
    }

    /**
     * The document, for this signer's own page to render.
     *
     * A route of its own rather than the one behind the workspace policy: this
     * visitor has no account for a policy to judge, and the token is what
     * stands in for one. The file is on the private disk either way — there is
     * no path to it that does not come through a controller.
     *
     * Served even when the link is spent, and that is deliberate: somebody who
     * has signed should be able to read what they signed, and refusing them
     * their own copy the moment the ink dries would be the wrong lesson.
     */
    public function document(Request $request, string $token): BinaryFileResponse
    {
        $signer = $this->signer($token);

        $media = $signer->contract->source();

        abort_if($media === null, 404);

        $path = $media->getPath();

        abort_unless(is_file($path), 404);

        $inline = ! $request->boolean('download');

        $response = response()->file($path, [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($media->file_name).'"',

            // No guessing around the type. See ContractController::source for
            // the longer version of why this is not optional.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    /**
     * Keep what has been typed so far.
     *
     * Called as somebody works rather than when they finish, so it has to be
     * cheap and it has to be forgiving: nothing is required of a draft, because
     * the whole point is that a person can stop halfway through a long contract
     * and come back. What is not forgiving is the shape — a date that is not a
     * date is a mistake whenever it is typed.
     *
     * Signing itself is a separate endpoint, and not merely for tidiness: this
     * one may be called fifty times and must never be the thing that commits
     * somebody to anything.
     */
    public function store(Request $request, string $token, SaveSignerDraft $draft): RedirectResponse
    {
        $signer = $this->signer($token);

        /*
         * Refused once the link is spent, although a draft writes nothing that
         * matters. A page that accepted edits to a signed contract would be
         * telling somebody their changes were kept.
         */
        abort_unless($signer->canStillSign(), 409);

        $validated = $request->validate($draft->rulesFor($signer));

        $draft->handle($signer, $validated['values']);

        return back();
    }

    /**
     * Which of the screens this person should be looking at.
     *
     * Five answers rather than a boolean, because each of them leads somewhere
     * different: three of them are dead ends with different explanations, one is
     * "je bent al klaar", and one is the contract itself.
     *
     * Asked in this order on purpose. Somebody who has already signed is told
     * so even if the contract has since expired — what they did is the more
     * useful fact, and "verlopen" would read as though their signature had not
     * counted.
     */
    private function state(ContractSigner $signer): string
    {
        return match (true) {
            $signer->hasSigned() => 'signed',
            $signer->hasDeclined() => 'declined',
            $signer->contract->status->isEvidence() => 'completed',
            $signer->contract->hasExpired() => 'expired',
            ! $signer->contract->status->isSignable() => 'cancelled',
            default => 'signing',
        };
    }

    /**
     * The signer behind this token, or nothing at all.
     *
     * Two things fall through to a plain 404 and only two: a token nobody
     * holds, and a workspace that has switched contracts off. Everything else —
     * expired, withdrawn, already signed — is a real person holding a real link
     * and gets told which, by show() above.
     *
     * The feature is asked about here by hand, because the middleware that
     * usually does it reads a workspace off the route and this route has none.
     */
    private function signer(string $token): ContractSigner
    {
        /*
         * Looked up by an exact where and then compared again with
         * hash_equals. The query is what finds the row; the comparison is what
         * makes the answer take the same time whether the guess was close or
         * nowhere near. On a 64-character random string that is a small leak,
         * but it is free to close and this is the one credential the outside
         * world holds.
         */
        $signer = ContractSigner::query()
            ->with(['contract.fields', 'contract.signers', 'contract.workspace'])
            ->where('token', $token)
            ->first();

        abort_if($signer === null, 404);
        abort_unless($signer->tokenMatches($token), 404);
        abort_unless($signer->contract->workspace->hasFeature(Contracts::class), 404);

        return $signer;
    }
}
