<?php

namespace App\Events;

use App\Models\FormSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody filled a form in and sent it.
 *
 * Two things hang off this and neither belongs in the action that stored the
 * answers: the bot message telling the author what came in, and any workflow
 * that was waiting for this form. Both are things that happen *after* a
 * submission is safe in the database, and a person who has just pressed
 * "versturen" should not wait for either.
 *
 * The submission carries everything both listeners need — its form, its answers
 * and whoever sent it — so nothing is repeated in the payload. It arrives with
 * those relations already loaded; a queued listener reloads what it needs
 * anyway, and this way a synchronous one does not go back to the database for
 * facts the action had in its hands.
 */
class FormSubmitted
{
    use Dispatchable;

    public function __construct(public readonly FormSubmission $submission) {}
}
