<?php

namespace App\Http\Controllers;

use App\Actions\UploadLinks\ReceiveUpload;
use App\Actions\UploadLinks\ResolveUploadLink;
use App\Http\Requests\ReceiveUploadRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sender's side of an upload link.
 *
 * Outside auth, like a transfer: whoever follows this is usually a customer
 * with no account and no wish for one. The token in the path is the whole of
 * the permission, which is why everything here is throttled at the route.
 *
 * Note what this never does: hand a file back. What arrives here is only ever
 * read by members, through UploadLinkController, as an attachment.
 */
class PublicUploadLinkController extends Controller
{
    public function __construct(private readonly ResolveUploadLink $resolve) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->resolve->handle($token);
        $locked = ! $this->resolve->isUnlocked($request, $link);

        return Inertia::render('upload-links/show', [
            'uploadLink' => [
                'title' => $link->title,
                // Kept back behind the password: the instructions can name the
                // very documents the lock is there to keep quiet about.
                'message' => $locked ? null : $link->message,
                'ownerName' => $link->owner?->name,
                'workspaceName' => $link->workspace->name,
                'expiresAt' => $link->expires_at,
                'state' => $link->state(),
                'isLocked' => $locked,
                'uploadsLeft' => $link->max_uploads === null
                    ? null
                    : max(0, $link->max_uploads - $link->uploads),
                'maxKb' => $link->workspace->max_transfer_kb,
                'submitUrl' => route('upload-links.submit', $token),
                'unlockUrl' => route('upload-links.unlock', $token),
            ],
        ]);
    }

    /**
     * Answer the password, and be remembered for this link only.
     *
     * A link with no password accepts nothing rather than everything, as on a
     * transfer — a session flag written for no reason is one a later change
     * might start trusting.
     */
    public function unlock(Request $request, string $token): RedirectResponse
    {
        $link = $this->resolve->handle($token);

        $given = $request->validate(['password' => ['required', 'string']])['password'];

        if (! $link->isLocked() || ! Hash::check($given, (string) $link->password)) {
            throw ValidationException::withMessages([
                'password' => __('requests.transfer.wrong_password'),
            ]);
        }

        $request->session()->put($link->unlockedSessionKey(), true);

        return back();
    }

    public function store(ReceiveUploadRequest $request, ReceiveUpload $receive): RedirectResponse
    {
        $link = $request->uploadLink();

        $submission = $link->isUsable()
            ? $receive->handle(
                uploadLink: $link,
                files: $request->file('files', []),
                name: $request->string('name')->trim()->value(),
                email: $request->string('email')->trim()->value() ?: null,
                note: $request->string('note')->trim()->value() ?: null,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            )
            : null;

        /*
         * Closed between opening the page and pressing send — the date passed,
         * somebody withdrew it, or the last allowed upload just came in from
         * somebody else. Said on the field, so the sender learns it before they
         * go looking for a confirmation that never comes.
         */
        if ($submission === null) {
            throw ValidationException::withMessages([
                'files' => __('requests.upload_link.closed'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.upload_link.received')]);

        return back()->with('uploadReceived', true);
    }
}
