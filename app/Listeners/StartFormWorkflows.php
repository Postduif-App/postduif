<?php

namespace App\Listeners;

use App\Actions\Workflows\StartWorkflow;
use App\Events\FormSubmitted;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Workflow;
use App\Workflows\Triggers\FormSubmittedTrigger;

/**
 * Set off the workflows that were waiting for answers to this form.
 *
 * The quiet counterpart of StartKeywordWorkflows: a form is sent in now and
 * then rather than a hundred times an hour, so nothing here has to be careful
 * about how often it runs. It still filters before it starts anything, for the
 * other reason that listener gives — a run written down and then found to be
 * about the wrong form is a row that never had a reason to exist.
 */
class StartFormWorkflows
{
    public function __construct(private readonly StartWorkflow $startWorkflow) {}

    public function handle(FormSubmitted $event): void
    {
        $submission = $event->submission;
        $form = $submission->form;
        $workspace = $form->workspace;

        $workflows = Workflow::query()
            ->listeningFor($workspace, FormSubmittedTrigger::key())
            ->get();

        foreach ($workflows as $workflow) {
            /*
             * Compared loosely and as strings because the id came out of a JSON
             * column, where a ULID may have been saved with whatever the browser
             * had in the box. The trigger requires a form, so an empty setting
             * is a half-written workflow rather than "every form" — and it is
             * skipped rather than run against all of them, which is the reading
             * that could send one workspace's answers somewhere nobody chose.
             */
            $chosen = $workflow->triggerSetting('form_id');

            if (blank($chosen) || (string) $chosen !== $form->id) {
                continue;
            }

            $this->startWorkflow->handle($workflow, $this->triggerData($form, $submission));
        }
    }

    /**
     * What the trigger saw, exactly as FormSubmittedTrigger::provides()
     * describes it.
     *
     * @return array{
     *     form: array{id: string, title: string},
     *     user: array{id: int|null, name: string},
     *     answers: array<string, string>,
     * }
     */
    private function triggerData(Form $form, FormSubmission $submission): array
    {
        return [
            'form' => ['id' => $form->id, 'title' => $form->title],

            /*
             * The id stays empty for an anonymous submission — there is nobody
             * a step may be pointed at — but the name is the word "anoniem"
             * rather than nothing.
             *
             * This is where forms part company with the keyword trigger, which
             * leaves a bot's name empty on purpose so that a half-written
             * greeting comes out visibly incomplete. Here the emptiness is not a
             * gap: the form promised the person who filled it in that we would
             * not know who they were, and that promise is a fact worth saying
             * out loud. "Nieuwe inzending van anoniem" is what happened;
             * "Nieuwe inzending van " reads as a bug and invites somebody to go
             * and fix it by looking the sender up.
             *
             * The same word covers a submitter whose account has since gone,
             * which is the other way a name can be missing here and reads the
             * same way to whoever gets the message.
             */
            'user' => [
                'id' => $submission->submitted_by,
                'name' => $submission->isAnonymous()
                    ? __('workflows.triggers.form-submitted.anonymous')
                    : $submission->submitter->name,
            ],

            // Keyed by field key, which is what makes
            // {{ trigger.answers.reden }} reach one particular question.
            'answers' => $submission->keyedAnswers(),
        ];
    }
}
