<?php

namespace App\Http\Controllers;

use App\Actions\Secrets\CreateSecretRequest;
use App\Http\Requests\StoreSecretRequestRequest;
use App\Models\Channel;
use App\Models\SecretRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Asking somebody for values that must not be typed into a conversation.
 *
 * The asking half lives here. Answering is somebody else's screen — often
 * somebody who is a guest in this workspace — and reading the answers is the
 * requester's alone; both are their own controllers for that reason.
 */
class SecretRequestController extends Controller
{
    public function store(
        StoreSecretRequestRequest $request,
        Workspace $workspace,
        Channel $channel,
        CreateSecretRequest $createSecretRequest,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $createSecretRequest->handle(
            channel: $channel,
            requester: $request->user(),
            title: $request->string('title')->trim()->value(),
            keys: $request->input('keys', []),
            validForDays: $request->integer('valid_for_days'),
            description: $request->string('description')->trim()->value() ?: null,
            burnAfterReading: $request->boolean('burn_after_reading'),
        );

        return back();
    }

    /**
     * Withdraw it.
     *
     * Marked rather than deleted, as an invite link is: the question is sitting
     * in a channel and whoever opens it deserves to be told it was withdrawn.
     * The answers already given stay readable to the requester — they were
     * given in good faith, and throwing them away would mean asking again.
     */
    public function destroy(Workspace $workspace, SecretRequest $secretRequest): RedirectResponse
    {
        abort_unless($secretRequest->workspace_id === $workspace->id, 404);

        $this->authorize('update', $secretRequest);

        if (! $secretRequest->isRevoked()) {
            $secretRequest->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Verzoek ingetrokken.']);

        return back();
    }
}
