<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SendMessage;
use App\Actions\Transfers\CreateTransfer;
use App\Enums\TransferAudience;
use App\Http\Requests\StoreTransferRequest;
use App\Models\Transfer;
use App\Models\TransferRecipient;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Files put aside for somebody, behind a link.
 *
 * Making one lives here rather than under settings, for the reason inviting
 * does: it is what you reach for while working, not a screen you go to. Keeping
 * track of what is still out there is administration, and that is
 * Settings\TransferController.
 */
class TransferController extends Controller
{
    public function store(
        StoreTransferRequest $request,
        Workspace $workspace,
        CreateTransfer $createTransfer,
        SendMessage $sendMessage,
    ): RedirectResponse {
        $transfer = $createTransfer->handle(
            workspace: $workspace,
            sender: $request->user(),
            files: $request->file('files', []),
            audience: TransferAudience::from($request->string('audience')->value()),
            validForDays: $request->integer('valid_for_days'),
            maxDownloads: $request->input('max_downloads') === null
                ? null
                : $request->integer('max_downloads'),
            title: $request->string('title')->trim()->value() ?: null,
            message: $request->string('message')->trim()->value() ?: null,
            recipients: $request->input('recipients', []),
            password: $request->string('password')->value() ?: null,
        );

        $channel = $request->announcementChannel();

        if ($channel !== null) {
            /*
             * Posted as an ordinary message, link and all. Not as a special
             * kind of message: what makes it read as more than a token is
             * PresentMessage drawing a card for it, and that works for a link
             * anybody pastes by hand just as well. One fewer thing that can be
             * true of a message.
             */
            $sendMessage->handle(
                channel: $channel,
                author: $request->user(),
                body: trim(($transfer->title ?? '').' '.route('transfers.show', $transfer->token)),
            );

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.transfer.created'),
        ]);

        /*
         * The link itself is not flashed along. It is on the page the sender is
         * sent back to, where it can be copied at leisure — and a toast is the
         * one place a secret should not be, because it is gone before anybody
         * has read it out.
         */
        return back()->with('transferId', $transfer->id);
    }

    /**
     * Withdraw it.
     *
     * Marked rather than deleted, as with an invite link: the link is out there
     * in somebody's mail, and whoever follows it deserves to be told it was
     * withdrawn rather than that it never existed. The files go with the prune
     * that follows expiry — a withdrawn transfer keeps its row, and its row is
     * the record of how often it was fetched before somebody stopped it.
     */
    public function destroy(Workspace $workspace, Transfer $transfer): RedirectResponse
    {
        abort_unless($transfer->workspace_id === $workspace->id, 404);

        $this->authorize('delete', $transfer);

        // Left alone if it was already withdrawn: the moment it stopped working
        // is the interesting one, and a second click should not move it.
        if (! $transfer->isRevoked()) {
            $transfer->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.transfer.withdrawn')]);

        return back();
    }

    /**
     * Take one address off the list.
     *
     * For the ordinary mistake this feature exists to survive: an address
     * mistyped, or a person who left the company between sending and reading.
     * The other recipients keep their links, which is the difference between
     * this and withdrawing the transfer — and the reason the addresses each
     * carry a token of their own rather than sharing one.
     */
    public function destroyRecipient(
        Workspace $workspace,
        Transfer $transfer,
        TransferRecipient $recipient,
    ): RedirectResponse {
        abort_unless($transfer->workspace_id === $workspace->id, 404);
        abort_unless($recipient->transfer_id === $transfer->id, 404);

        $this->authorize('delete', $transfer);

        if (! $recipient->isRevoked()) {
            $recipient->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.transfer.link_withdrawn', ['email' => $recipient->email])]);

        return back();
    }
}
