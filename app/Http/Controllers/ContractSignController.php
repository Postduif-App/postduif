<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\DeclineContract;
use App\Actions\Contracts\PresentContractForSigner;
use App\Actions\Contracts\SaveSignerDraft;
use App\Actions\Contracts\SignContract;
use App\Actions\Contracts\SigningRefused;
use App\Actions\Contracts\StoreSignature;
use App\Enums\ContractFieldType;
use App\Enums\SignatureMethod;
use App\Features\Contracts;
use App\Models\ContractSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
     * The signed copy, for somebody who signed it.
     *
     * Behind the token rather than the policy, because this person has no
     * account for a policy to judge — and they have every claim to the document
     * they put their name under. Withholding it would be the one thing this
     * feature must not do: a signature nobody can produce the document for is
     * not evidence of anything.
     *
     * Reachable once the contract is finished and not before, including to
     * somebody who has already signed while others have not. Handing out a
     * half-finished record would mean handing out a document that says less
     * than the final one does.
     */
    public function signedCopy(string $token): BinaryFileResponse
    {
        $signer = $this->signer($token);

        abort_unless($signer->contract->status->isEvidence(), 404);

        $media = $signer->contract->signedCopy();

        abort_if($media === null || ! is_file($media->getPath()), 404);

        $response = response()->file($media->getPath(), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
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
     * Put down a signature or a set of initials.
     *
     * The image arrives as a file rather than as a data URL in the body, and
     * that is worth saying out loud: a base64 string would be a third larger,
     * would have to be decoded and written by hand, and would arrive through a
     * validator that knows nothing about images. An upload goes through the
     * same machinery every other file in this application does.
     *
     * What is not trusted is the type. The rule below judges the bytes, not the
     * name — this endpoint is reachable by anybody holding a token, and what it
     * stores gets painted onto a document other people will open.
     */
    public function signature(Request $request, string $token, StoreSignature $store): RedirectResponse
    {
        $signer = $this->signer($token);

        abort_unless($signer->canStillSign(), 409);

        $data = $request->validate([
            /*
             * Signature or initials, and nothing else. The enum is asked rather
             * than a list of two strings, so a type added later cannot be
             * silently unreachable here.
             */
            'kind' => ['required', Rule::enum(ContractFieldType::class)],
            'method' => ['required', Rule::enum(SignatureMethod::class)],

            /*
             * A small PNG. Small because a signature is a few strokes: anything
             * approaching a megabyte is a photograph, and a photograph of
             * somebody's passport is not what this box is for.
             */
            'image' => ['required', 'file', 'mimetypes:image/png', 'max:512'],

            // Only meaningful for the typed method; see StoreSignature.
            'typed' => ['nullable', 'string', 'max:120'],
        ]);

        $kind = ContractFieldType::from($data['kind']);

        abort_unless($kind->isDrawn(), 422);

        $store->handle(
            signer: $signer,
            type: $kind,
            image: $request->file('image'),
            method: SignatureMethod::from($data['method']),
            typed: $data['typed'] ?? null,
        );

        return back();
    }

    /**
     * Take it away and start again.
     *
     * A DELETE on the same address rather than a flag, because "wissen" is a
     * thing a mark either is or is not — and because somebody who drew a wobbly
     * line with a mouse should be one button away from a clean box.
     */
    public function clearSignature(Request $request, string $token, StoreSignature $store): RedirectResponse
    {
        $signer = $this->signer($token);

        abort_unless($signer->canStillSign(), 409);

        $kind = ContractFieldType::from($request->validate([
            'kind' => ['required', Rule::enum(ContractFieldType::class)],
        ])['kind']);

        abort_unless($kind->isDrawn(), 422);

        $store->clear($signer, $kind);

        return back();
    }

    /**
     * The mark itself, so the page can show what was just put down.
     *
     * Behind the same token as everything else here rather than on the public
     * disk, and this is the one image in the application where that matters
     * most: it is a picture of a person's name, and a public path would leave
     * it one guess away from anybody who wanted to paste it onto something else.
     */
    public function signatureImage(string $token, string $kind): BinaryFileResponse
    {
        $signer = $this->signer($token);

        $type = ContractFieldType::tryFrom($kind);

        abort_if($type === null || ! $type->isDrawn(), 404);

        $media = $signer->mark($type);

        abort_if($media === null, 404);

        $path = $media->getPath();

        abort_unless(is_file($path), 404);

        $response = response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$kind.'.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        /*
         * Both set after the response is built rather than handed in with the
         * headers above, and for the same reason: a file response computes
         * these for itself from the bytes on disk and from its own caching
         * rules, and quietly overrules anything passed in. Content-Type would
         * become a second opinion nobody asked for, and Cache-Control comes out
         * as "public" — which is the opposite of what a picture of somebody's
         * name wants.
         *
         * Never cached, because the mark can be cleared and drawn again: a
         * browser holding the old one would show somebody the signature they
         * just replaced, on the very page where they are deciding whether to
         * commit to it.
         */
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Put your name to it.
     *
     * The one request in this feature that cannot be taken back, which is why
     * everything it needs to be sure of is checked in the action rather than
     * here — see SignContract. What this method owns is the two facts that only
     * a request can supply: where it came from and what it was made with.
     */
    public function complete(Request $request, string $token, SignContract $sign): RedirectResponse
    {
        $signer = $this->signer($token);

        try {
            $sign->handle(
                signer: $signer,
                /*
                 * The address as this application sees it. Behind a proxy that
                 * is the proxy's own unless TrustProxies says otherwise, which
                 * it does — see the middleware. Recorded as evidence rather
                 * than used for anything, so it is never a reason to refuse
                 * somebody: an audit trail with "onbekend" in it is worth more
                 * than a signature that could not be given.
                 */
                ip: (string) $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (SigningRefused $refused) {
            /*
             * Back to the page with the reason on it. Every message this can
             * carry is written for somebody standing in front of the screen —
             * see the contracts.sign.errors keys — because all but one of them
             * is something they can act on.
             */
            return back()->withErrors(['signing' => $refused->getMessage()]);
        }

        return back();
    }

    /**
     * Say no.
     *
     * Its own endpoint rather than a flag on the one above, and its own button
     * on the page. Without it, refusing means closing the tab — which is
     * indistinguishable from being on holiday, and leaves the author waiting
     * for somebody who has already decided.
     */
    public function decline(Request $request, string $token, DeclineContract $decline): RedirectResponse
    {
        $signer = $this->signer($token);

        $data = $request->validate([
            /*
             * Optional, and asked for anyway. "Niet akkoord met artikel 4" is
             * the difference between a contract that gets amended and one that
             * gets sent again unchanged.
             */
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $decline->handle($signer, $data['reason'] ?? null);
        } catch (SigningRefused $refused) {
            return back()->withErrors(['signing' => $refused->getMessage()]);
        }

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
