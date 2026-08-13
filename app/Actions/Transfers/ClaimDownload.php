<?php

namespace App\Actions\Transfers;

use App\Events\TransferDownloaded;
use App\Models\Transfer;
use App\Models\TransferDownload;
use App\Models\TransferRecipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ClaimDownload
{
    /**
     * Take one off the transfer's allowance, or refuse.
     *
     * The check and the increment are one step on purpose. Read the count,
     * decide, then write it, and two recipients clicking at the same moment
     * both read "0 of 1 used" and both get the file — which makes a limit of
     * one a limit of however many people happen to click together. The row is
     * locked for the length of the decision, so the second request waits and
     * then finds the transfer used up.
     *
     * Claimed before the bytes go out rather than after. If the download then
     * fails halfway, a fetch has been counted that nobody received — which is
     * the wrong way round from a customer's point of view, but the right way
     * round from the sender's: a limit of one that hands the file out twice is
     * a broken promise, and a limit of one that occasionally needs re-sending
     * is an inconvenience.
     *
     * @param  TransferRecipient|null  $recipient  Whose link this was, when the
     *                                             transfer was addressed to people rather than to the world. Their
     *                                             own counter moves too — five recipients are five counters, which
     *                                             is what lets a sender see that the link they gave to one address
     *                                             has been used three times.
     * @param  int|null  $mediaId  Which file, or null when the whole pile went
     *                             out as one archive.
     *
     * @throws HttpException 410, when the link has stopped working.
     */
    public function handle(
        Request $request,
        Transfer $transfer,
        ?TransferRecipient $recipient = null,
        ?int $mediaId = null,
    ): void {
        DB::transaction(function () use ($request, $transfer, $recipient, $mediaId): void {
            /** @var Transfer|null $locked */
            $locked = Transfer::whereKey($transfer->getKey())->lockForUpdate()->first();

            /*
             * 410 rather than 404: the recipient followed a link that really was
             * theirs, and "this is gone" is both true and the useful thing to
             * say. The landing page tells them which of the three it was.
             */
            abort_if($locked === null || ! $locked->isUsable(), 410);

            $locked->increment('downloads');

            // The caller is holding the model the request was resolved from,
            // and it would otherwise still be showing the old count to anything
            // that looks at it after this.
            $transfer->setAttribute('downloads', $locked->downloads);

            /*
             * Inside the same transaction as the shared counter, so the two
             * cannot drift: a per-recipient tally that says four while the
             * transfer says three is worse than no tally at all, because it is
             * the one a sender would act on.
             *
             * Not locked in turn — nobody decides anything from this number, so
             * a second lock would buy only contention.
             */
            $recipient?->forceFill(['last_downloaded_at' => now()])->save();
            $recipient?->increment('downloads');

            /*
             * The line in the log, in the same transaction as the counter it
             * belongs to. Apart they would drift, and a log that says four
             * where the counter says three is worse than no log — it is the one
             * a sender would act on.
             */
            TransferDownload::create([
                'transfer_id' => $transfer->id,
                'transfer_recipient_id' => $recipient?->id,
                'user_id' => $request->user()?->id,
                'media_id' => $mediaId,
                'ip' => $request->ip(),
                // Truncated rather than trusted: the header is whatever the
                // client felt like sending, and the column has a size.
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            ]);
        });

        /*
         * Outside the transaction, and carrying nothing but who and which —
         * what is in a transfer is the sender's business and a workflow's
         * business is only that it was collected. See the event.
         */
        TransferDownloaded::dispatch($transfer->id, $recipient?->id, $request->user()?->id);
    }
}
