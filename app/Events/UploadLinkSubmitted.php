<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody sent files in through an upload link.
 *
 * Only the id of the submission: whoever listens looks up what it may know.
 * What is inside the files is never part of it — a workflow learns that
 * something came in, from whom and how much, and that is all.
 */
class UploadLinkSubmitted
{
    use Dispatchable;

    public function __construct(public readonly string $submissionId) {}
}
