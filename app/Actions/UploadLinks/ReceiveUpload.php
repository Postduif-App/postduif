<?php

namespace App\Actions\UploadLinks;

use App\Events\UploadLinkSubmitted;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ReceiveUpload
{
    public function __construct(private readonly NotifyUploadLinkOwner $notifyOwner) {}

    /**
     * Take in what somebody sent through an upload link.
     *
     * The row, the files and the counter are one thing, so they go in one
     * transaction: a submission without its files is news about nothing, and
     * files without a row are bytes nobody will ever find again.
     *
     * The link is locked and asked again inside the transaction rather than
     * trusted from the controller. Two browsers submitting at the same moment
     * to a link that takes one upload would otherwise both see "nog open" and
     * both get in.
     *
     * @param  array<int, UploadedFile>  $files
     * @return UploadLinkSubmission|null Null when the link closed in the
     *                                   meantime — the caller says so.
     */
    public function handle(
        UploadLink $uploadLink,
        array $files,
        string $name,
        ?string $email = null,
        ?string $note = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): ?UploadLinkSubmission {
        $submission = DB::transaction(function () use ($uploadLink, $files, $name, $email, $note, $ip, $userAgent): ?UploadLinkSubmission {
            $locked = UploadLink::query()->lockForUpdate()->find($uploadLink->id);

            if ($locked === null || ! $locked->isUsable()) {
                return null;
            }

            $submission = UploadLinkSubmission::create([
                'upload_link_id' => $locked->id,
                'name' => $name,
                'email' => $email,
                'note' => $note,
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            ]);

            foreach ($files as $file) {
                // The sender's name for the file is kept for display; a random
                // one goes on disk, the library's default.
                $submission->addMedia($file)->toMediaCollection(UploadLinkSubmission::FILES);
            }

            $locked->increment('uploads');

            return $submission;
        });

        if ($submission === null) {
            return null;
        }

        /*
         * After the commit, never inside it: the mail is the one side effect
         * with no rollback, and a notification about a submission that was
         * then rolled back would send somebody to look for files that are not
         * there.
         */
        $this->notifyOwner->handle($submission);

        UploadLinkSubmitted::dispatch($submission->id);

        return $submission;
    }
}
