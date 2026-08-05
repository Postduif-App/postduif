<?php

namespace App\Listeners;

use App\Actions\Chat\SendMessage;
use App\Actions\Chat\StartDirectMessage;
use App\Events\FormSubmitted;
use App\Models\Channel;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * Bring a filled-in form to the person who asked the questions.
 *
 * The answers are always safe on the answers screen; this is the part that
 * makes somebody notice them without going to look. Which is why every route
 * below may end in silence: a form whose author left, or an anonymous
 * submission to a form with no channel named, has nowhere to be delivered and
 * that is a shrug, not a failure.
 *
 * Delivered as a bot message in a conversation that already exists, rather than
 * as a notification or a mail: this application says things where the work
 * happens. There is no such thing as a DM with a bot here — see
 * SendDirectMessage, which makes the same construction for the same reason —
 * so a named submission goes into the conversation between the two people, with
 * the form as the visible sender.
 */
class SendFormAnswers implements ShouldQueue
{
    use Localizable;

    /**
     * After the transaction, not merely after the save.
     *
     * The submission and its answers are written inside one transaction, so a
     * worker that picked this up on dispatch could reach the row before it
     * exists — or worse, read the submission and find no answers yet, and send
     * a cheerful message containing nothing. Waiting for the commit is what
     * makes "the answers" mean all of them.
     */
    public bool $afterCommit = true;

    /**
     * Once.
     *
     * A posted message cannot be taken back, and a retry after a partial
     * failure would put a second copy of the same answers in somebody's
     * conversation. The submission is stored either way, so the worst a failure
     * costs is a trip to the answers screen.
     */
    public int $tries = 1;

    public function __construct(
        private readonly StartDirectMessage $startDirectMessage,
        private readonly SendMessage $sendMessage,
    ) {}

    public function handle(FormSubmitted $event): void
    {
        $submission = $event->submission;

        /*
         * loadMissing rather than load: the event carries these already when
         * nothing queued it, and reloading them would be two queries spent
         * confirming what is in hand.
         */
        $submission->loadMissing(['form.author', 'form.notifyChannel', 'answers', 'submitter']);

        $form = $submission->form;
        $author = $form->author;

        // Nobody to tell. The account that made this form is gone, and the
        // answers stay where they are.
        if ($author === null) {
            return;
        }

        $channel = $this->destination($submission, $form, $author);

        if ($channel === null) {
            return;
        }

        $this->sendMessage->fromSystem($channel, $this->body($submission, $form, $author), $this->botName($form));
    }

    /**
     * Where this submission's answers should land, or null when there is
     * nowhere sensible.
     *
     * The conversation with the person who filled it in comes first, because
     * that is the one place where the reply the author may want to write is
     * already addressed to the right person. Everything the DM cannot carry —
     * a stranger through the public link, or the author trying their own form —
     * falls to the channel they named for exactly that, and the two never both
     * fire: one submission is one message.
     */
    private function destination(FormSubmission $submission, Form $form, User $author): ?Channel
    {
        $submitter = $submission->submitter;

        if ($submitter !== null && ! $submitter->is($author)) {
            return $this->startDirectMessage->handle($form->workspace, $submitter, $author);
        }

        $channel = $form->notifyChannel;

        if ($channel === null) {
            return null;
        }

        /*
         * A channel from another workspace is not a delivery, it is a leak: the
         * notify_channel_id is picked in a form somebody may have edited since,
         * and answers must never surface in a workspace whose members were
         * never asked the questions.
         *
         * An archived channel is refused for the softer reason that nobody
         * reads it — the same judgement AnnounceTicket makes about a channel
         * that has moved on.
         */
        if ($channel->workspace_id !== $form->workspace_id || $channel->archived_at !== null) {
            return null;
        }

        return $channel;
    }

    /**
     * The message, written in the author's language.
     *
     * A queued listener inherits the locale of whoever dispatched it, which
     * here is the person who filled the form in — so without this switch a form
     * made by a Dutch colleague would report back in English because a visitor's
     * browser asked for English. The whole body is built inside the switch,
     * answers included: FormFieldType::display() translates "ja" and the dash
     * for an empty answer, and a message half in one language reads worse than
     * one wholly in the wrong one.
     *
     * An author with no language of their own gets the application default
     * rather than the current locale. Leaving it alone — what preferredLocale()
     * means by null, and what Localizable does with it — would hand them
     * whatever the filler happened to be reading in, which is the one answer we
     * know nothing supports.
     */
    private function body(FormSubmission $submission, Form $form, User $author): string
    {
        return $this->withLocale(
            $author->preferredLocale() ?? (string) config('app.locale'),
            fn (): string => implode("\n\n", array_filter([
                $this->intro($submission, $form),
                implode("\n", $submission->answers->map($this->line(...))->all()),
                __('forms.dm.answers'),
            ])),
        );
    }

    private function intro(FormSubmission $submission, Form $form): string
    {
        if ($submission->isAnonymous()) {
            return __('forms.dm.anonymous_intro', ['form' => $form->title]);
        }

        $intro = __('forms.dm.intro', [
            'name' => $submission->submitter->name,
            'form' => $form->title,
        ]);

        // A member who followed the shared link rather than the card in a
        // channel still has a name, but how they got there explains why they
        // could answer a form they were never shown — worth a few words.
        return $submission->via_link
            ? $intro.' '.__('forms.answers.via_link')
            : $intro;
    }

    /**
     * One question and what was said to it.
     *
     * Every question gets a line, including an optional one left empty, which
     * display() renders as a dash. Leaving those out would make the message
     * look like a shorter form than the one that was sent, and "she skipped the
     * reason" is itself an answer the author would otherwise have to open the
     * answers screen to learn.
     *
     * The bold marker is safe here even for an answer running over several
     * lines: emphasis in this application stops at a newline, so the pair
     * around the question closes before anything the answerer typed.
     */
    private function line(FormAnswer $answer): string
    {
        return __('forms.dm.line', [
            'question' => $answer->question,
            'answer' => $answer->display(),
        ]);
    }

    /**
     * The name the message is posted under.
     *
     * The form's own title, not a constant like AnnounceTicket's "Tickets". In
     * a channel a constant is what makes one recognisable voice among many
     * senders — but this lands in a two-person conversation, where the sender
     * line is the first and sometimes the only thing read, and "Vakantieaanvraag"
     * says at a glance what "Formulieren" would take a sentence to explain.
     *
     * Cut short because a title has room to be a sentence and a sender name does
     * not; the intro repeats the title in full anyway.
     */
    private function botName(Form $form): string
    {
        return Str::limit($form->title, 60);
    }
}
