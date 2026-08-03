<?php

namespace App\Http\Controllers;

use App\Actions\Transfers\ClaimDownload;
use App\Features\Transfers;
use App\Models\Transfer;
use App\Models\TransferRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\MediaStream;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The recipient's side of a transfer.
 *
 * Outside the auth middleware, like invitations.show and invite-links.show: the
 * person following this link may have no account and may never get one. The
 * token in the URL is the whole of the credential, which is why it is looked up
 * rather than guessed at, and why everything here is throttled.
 *
 * Note what this does not do: it never renders anything in place. Every file
 * leaves as an attachment, and that is what lets the sending side accept file
 * types a message never would — an uploaded .html served inline would run its
 * script on our own origin.
 */
class PublicTransferController extends Controller
{
    public function __construct(private ClaimDownload $claimDownload) {}

    /**
     * What is waiting, or why it is not.
     *
     * A page rather than a bare 404 for a link that has stopped working: the
     * three reasons are kept apart on the model precisely so this page can say
     * which one it is. "Ask them to send it again" and "ask them why they
     * withdrew it" are different next steps for whoever is holding the link.
     */
    public function show(Request $request, string $token): InertiaResponse|RedirectResponse
    {
        [$transfer] = $this->resolve($token);

        if ($redirect = $this->refuseAudience($request, $transfer)) {
            return $redirect;
        }

        // Worked out once: it decides three things below, and three copies of
        // the same condition is three chances to get one of them wrong.
        $locked = $transfer->isLocked() && ! $this->isUnlocked($request, $transfer);
        $handsOver = $transfer->isUsable() && ! $locked;

        return Inertia::render('transfers/show', [
            'transfer' => [
                'title' => $transfer->title,
                'message' => $transfer->message,
                'senderName' => $transfer->sender?->name,
                'workspaceName' => $transfer->workspace->name,
                'expiresAt' => $transfer->expires_at,
                'downloadsLeft' => $transfer->max_downloads === null
                    ? null
                    : max(0, $transfer->max_downloads - $transfer->downloads),

                // One word for why it is not working, so the page does not have
                // to re-derive it from three nullable fields and get it subtly
                // different — the same shape the invite-link list uses.
                'state' => match (true) {
                    $transfer->isRevoked() => 'revoked',
                    $transfer->hasExpired() => 'expired',
                    $transfer->isExhausted() => 'exhausted',
                    default => 'usable',
                },

                /*
                 * A lock is not a fourth reason it stopped working — the
                 * transfer is alive and the visitor is one step short. Sent
                 * beside the state rather than folded into it so the page can
                 * show a password field where it would otherwise show the
                 * files, and the dead-end texts stay about dead ends.
                 */
                'isLocked' => $locked,

                /*
                 * Nothing about the contents while the link is not working. A
                 * withdrawn transfer should not still be telling the world what
                 * it was carrying — the file names alone can be the sensitive
                 * part.
                 */
                'files' => $handsOver
                    ? $transfer->files()->map(fn (Media $media): array => [
                        'id' => $media->id,
                        'name' => $media->file_name,
                        'size' => $media->size,
                        'url' => route('transfers.download', [$token, $media->id]),
                    ])->all()
                    : [],
                'downloadAllUrl' => $handsOver
                    ? route('transfers.download-all', $token)
                    : null,
                'unlockUrl' => route('transfers.unlock', $token),
            ],
        ]);
    }

    /** One file out of the pile. */
    public function download(Request $request, string $token, Media $media): BinaryFileResponse
    {
        [$transfer, $recipient] = $this->resolve($token);
        $this->requireAudience($request, $transfer);
        $this->requireUnlocked($request, $transfer);

        abort_unless(
            $media->model_type === $transfer->getMorphClass()
                && (string) $media->model_id === (string) $transfer->id,
            404,
        );

        $this->claimDownload->handle($request, $transfer, $recipient, $media->id);

        $path = $media->getPath();
        abort_unless(is_file($path), 404);

        $response = response()->file($path, [
            // Always an attachment. See the note on the class: this is what
            // makes accepting any file type on the way in defensible.
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set afterwards rather than handed in: a file response fills in the
        // type from the bytes on disk, which is a second opinion we did not ask
        // for and one an uploader gets to influence.
        $response->headers->set('Content-Type', 'application/octet-stream');

        return $response;
    }

    /**
     * The lot, as one archive.
     *
     * Counted as a single fetch, which is the sensible reading of "how often
     * may this be downloaded" for something that was sent as one thing. Note
     * that fetching the files one by one costs one each — the page says how
     * many are left, so the choice is at least visible.
     */
    public function downloadAll(Request $request, string $token): MediaStream
    {
        [$transfer, $recipient] = $this->resolve($token);
        $this->requireAudience($request, $transfer);
        $this->requireUnlocked($request, $transfer);

        $files = $transfer->files();
        abort_if($files->isEmpty(), 404);

        // No media id: the whole pile went out as one thing.
        $this->claimDownload->handle($request, $transfer, $recipient);

        $name = $transfer->title === null
            ? 'bestanden.zip'
            : str($transfer->title)->slug()->append('.zip')->value();

        return MediaStream::create($name)->addMedia($files);
    }

    /**
     * Answer the password, and be remembered for this transfer.
     *
     * Throttled at the route, because a password on a public URL is one anybody
     * may sit and guess at — the length floor on the way in and the throttle
     * here are the two halves of the same protection.
     */
    public function unlock(Request $request, string $token): RedirectResponse
    {
        [$transfer] = $this->resolve($token);
        $this->requireAudience($request, $transfer);

        $given = $request->validate(['password' => ['required', 'string']])['password'];

        /*
         * A transfer with no password accepts nothing rather than everything.
         * Without this, posting to an unlocked transfer would happily write the
         * session flag — harmless today, and exactly the sort of thing that
         * stops being harmless when a later change reads that flag.
         */
        if (! $transfer->isLocked() || ! Hash::check($given, (string) $transfer->password)) {
            throw ValidationException::withMessages([
                'password' => 'Dat wachtwoord klopt niet.',
            ]);
        }

        $request->session()->put($transfer->unlockedSessionKey(), true);

        return back();
    }

    /** Whether this browser has already answered for this particular transfer. */
    private function isUnlocked(Request $request, Transfer $transfer): bool
    {
        return $request->session()->get($transfer->unlockedSessionKey()) === true;
    }

    /**
     * No file without the password.
     *
     * A 403 rather than the 404 the audience checks use, and the difference is
     * deliberate: the visitor has already been shown that this transfer exists —
     * they are looking at its page — so pretending otherwise would only be
     * confusing. What they are missing is the password, and that is what the
     * status says.
     */
    private function requireUnlocked(Request $request, Transfer $transfer): void
    {
        if (! $transfer->isLocked()) {
            return;
        }

        abort_unless($this->isUnlocked($request, $transfer), 403);
    }

    /**
     * Whether this visitor is the audience the sender chose, and where to send
     * them if they might yet be.
     *
     * A redirect rather than a refusal for somebody who is simply not signed in:
     * a colleague following a members-only link from their mail has done nothing
     * wrong, and the login screen is the answer to their situation rather than
     * an error page. Signed in but not a member is a different matter, and gets
     * the 404 that a token nobody recognises gets — for the reason
     * EnsureFeatureIsActive gives: "not you" tells somebody the thing is there.
     */
    private function refuseAudience(Request $request, Transfer $transfer): ?RedirectResponse
    {
        if (! $transfer->audience->requiresSignIn()) {
            return null;
        }

        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless($transfer->workspace->hasMember($user), 404);

        return null;
    }

    /**
     * The same question for a route that hands over bytes.
     *
     * No redirect here: a download URL is followed by the browser rather than
     * typed, so sending it to a login screen would produce an HTML page where a
     * file was expected. Somebody who is not signed in gets the same 404 as
     * somebody who is not a member — they can open the landing page, which does
     * know how to send them to log in.
     */
    private function requireAudience(Request $request, Transfer $transfer): void
    {
        if (! $transfer->audience->requiresSignIn()) {
            return;
        }

        $user = $request->user();

        abort_if($user === null, 404);
        abort_unless($transfer->workspace->hasMember($user), 404);
    }

    /**
     * What this token stands for: the transfer, and the person it was made for
     * when it was made for a person.
     *
     * Two kinds of token arrive on the same route. Deliberately the same route:
     * a recipient should not be able to tell from the shape of their URL that
     * other kinds exist, and the sender should not have to explain which link
     * they are pasting.
     *
     * A 404 for a token nobody recognises, and the feature check is part of the
     * same answer: a workspace that switched sending off has no such link, and
     * saying anything else would leave a door open the beheerder believes is
     * shut. Not a 403 for either, for the reason EnsureFeatureIsActive gives —
     * telling somebody a thing exists but is not for them is itself an answer.
     *
     * @return array{0: Transfer, 1: TransferRecipient|null}
     */
    private function resolve(string $token): array
    {
        $transfer = Transfer::with(['workspace', 'sender'])
            ->where('token', $token)
            ->first();

        if ($transfer !== null) {
            /*
             * The shared token, on a transfer that was addressed to named
             * people. It opens nothing — otherwise the list of addresses would
             * be a suggestion, and the sender who chose it would be relying on
             * a restriction that is not applied.
             */
            abort_unless($transfer->audience->opensWithTransferToken(), 404);

            $this->ensureOffered($transfer);

            return [$transfer, null];
        }

        $recipient = TransferRecipient::with(['transfer.workspace', 'transfer.sender'])
            ->where('token', $token)
            ->first();

        abort_if($recipient === null, 404);

        // Withdrawn on its own — one mistyped address should not have cost the
        // other four their link, and it must not leave this one working either.
        abort_if($recipient->isRevoked(), 404);

        $this->ensureOffered($recipient->transfer);

        return [$recipient->transfer, $recipient];
    }

    /** A workspace that switched sending off has no links at all. */
    private function ensureOffered(Transfer $transfer): void
    {
        abort_unless($transfer->workspace->hasFeature(Transfers::class), 404);
    }
}
