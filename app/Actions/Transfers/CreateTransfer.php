<?php

namespace App\Actions\Transfers;

use App\Enums\TransferAudience;
use App\Mail\TransferReadyMail;
use App\Models\Transfer;
use App\Models\TransferRecipient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class CreateTransfer
{
    /**
     * Put files aside behind a link.
     *
     * An action rather than a few lines in the controller, because the row and
     * the files are one thing: a transfer whose row exists without its files is
     * a link that hands over nothing, and one whose files exist without a row
     * is bytes nobody will ever come back for. The transaction is what keeps
     * those two from happening.
     *
     * @param  array<int, UploadedFile>  $files  At least one — a transfer with
     *                                           nothing in it is not a thing anybody meant to make.
     * @param  TransferAudience  $audience  Who the link works for.
     * @param  int  $validForDays  Counted from now. Never null, unlike an invite
     *                             link: expiry is what hands the storage back.
     * @param  int|null  $maxDownloads  Null for as often as anybody likes.
     * @param  string|null  $password  Something the recipient has to know as
     *                                 well as hold, or null for a link that is enough on its own.
     * @param  array<int, string>  $recipients  The addresses this is for, each of
     *                                          which gets a link of its own. Only meaningful for the
     *                                          NamedRecipients audience; ignored otherwise, because a
     *                                          list of addresses on an open link would suggest a
     *                                          restriction that is not being applied.
     */
    public function handle(
        Workspace $workspace,
        User $sender,
        array $files,
        TransferAudience $audience,
        int $validForDays,
        ?int $maxDownloads = null,
        ?string $title = null,
        ?string $message = null,
        array $recipients = [],
        ?string $password = null,
    ): Transfer {
        $transfer = DB::transaction(function () use ($workspace, $sender, $files, $audience, $validForDays, $maxDownloads, $title, $message, $recipients, $password): Transfer {
            $transfer = Transfer::create([
                'workspace_id' => $workspace->id,
                'created_by' => $sender->id,
                'token' => Transfer::freshToken(),
                'audience' => $audience,
                // Hashed here rather than by a cast, so there is exactly one
                // place that decides a transfer password is a password.
                'password' => $password === null ? null : Hash::make($password),
                'title' => $title,
                'message' => $message,
                'expires_at' => now()->addDays($validForDays),
                'max_downloads' => $maxDownloads,
            ]);

            foreach ($files as $file) {
                /*
                 * The uploaded name is kept as the display name and a random
                 * one goes on disk — the library's own default. It matters more
                 * here than for an attachment: the name is what the recipient
                 * sees before deciding to spend a gigabyte on it.
                 */
                $transfer->addMedia($file)->toMediaCollection(Transfer::FILES);
            }

            if ($audience === TransferAudience::NamedRecipients) {
                $this->address($transfer, $recipients);
            }

            return $transfer;
        });

        /*
         * Mailed after the transaction, never inside it. These mailables are
         * sent rather than queued, so a send inside the transaction that then
         * rolled back would have put a link to a non-existent transfer in
         * somebody's inbox — and a mail is the one side effect there is no
         * rollback for.
         */
        $transfer->recipients->each(
            fn (TransferRecipient $recipient) => Mail::to($recipient->email)
                ->send(new TransferReadyMail($recipient)),
        );

        return $transfer;
    }

    /**
     * Give each address its own link.
     *
     * Only the rows; the mail goes out after the transaction commits — see
     * handle().
     *
     * @param  array<int, string>  $addresses
     */
    private function address(Transfer $transfer, array $addresses): void
    {
        foreach (array_unique($addresses) as $email) {
            TransferRecipient::create([
                'transfer_id' => $transfer->id,
                'email' => $email,
                'token' => TransferRecipient::freshToken(),
            ]);
        }
    }
}
