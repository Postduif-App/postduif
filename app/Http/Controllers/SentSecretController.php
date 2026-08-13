<?php

namespace App\Http\Controllers;

use App\Actions\Secrets\SendSecret;
use App\Http\Requests\StoreSentSecretRequest;
use App\Models\Channel;
use App\Models\SentSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sending a secret from a channel, and taking one back.
 *
 * The mirror of SecretRequestController. What is different, and what the whole
 * feature turns on: the response to store() carries the id of the thing just
 * made, and the browser — which is the only place the key exists — builds the
 * complete link from it. The server could not produce that link if it wanted to.
 */
class SentSecretController extends Controller
{
    public function __construct(private readonly SendSecret $sendSecret) {}

    public function store(StoreSentSecretRequest $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $this->channelIsReachable($workspace, $channel);

        $recipient = User::query()->findOrFail($request->integer('recipient_id'));

        $secret = $this->sendSecret->handle(
            workspace: $workspace,
            channel: $channel,
            sender: $request->user(),
            recipient: $recipient,
            label: $request->string('label')->toString(),
            ciphertext: $request->string('ciphertext')->toString(),
            iv: $request->string('iv')->toString(),
            validForDays: $request->integer('valid_for_days'),
            password: $request->filled('password')
                ? $request->string('password')->toString()
                : null,
        );

        /*
         * Only the address goes back, and only to the sender who just posted it.
         * The dialog already holds the key; it puts the two together into the
         * link it then shows once. Sending a complete link from here would mean
         * the server had the key, which is the one thing this design exists to
         * avoid.
         *
         * Flashed rather than returned as a prop: this belongs to the request
         * that made it and must not survive into the next page load, where it
         * would sit in the history entry of a screen the sender has moved on
         * from.
         */
        Inertia::flash('sentSecret', [
            'id' => $secret->id,
            'url' => route('sent-secrets.show', $secret->id),
        ]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, SentSecret $sentSecret): RedirectResponse
    {
        abort_unless($sentSecret->workspace_id === $workspace->id, 404);

        $this->authorize('withdraw', $sentSecret);

        $this->sendSecret->withdraw($sentSecret);

        return back();
    }
}
