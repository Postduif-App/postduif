<?php

namespace App\Actions\Forms;

use App\Enums\FormFieldType;
use App\Events\FormSubmitted;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Take what somebody typed and turn it into a submission.
 *
 * The two doors — a member filling one in from a channel, a stranger following
 * the shared link — arrive here with the same three facts: the form, who it was
 * if anybody, and a map of answers keyed by field key. Everything that differs
 * between those doors is decided before this point, in the policy or in the
 * token, so this action never has to ask which one it is serving.
 *
 * Validation lives here too, in rulesFor(), rather than in a form request. The
 * rules are made out of the form's own fields, so both controllers would
 * otherwise build the same thing twice — and the request has no business
 * knowing that a multiple-choice answer is an array.
 */
class SubmitForm
{
    /**
     * The validation the answers have to survive, keyed the way the payload is.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rulesFor(Form $form): array
    {
        $rules = ['answers' => ['required', 'array']];

        foreach ($form->fields as $field) {
            $rules['answers.'.$field->key] = $field->type->rules($field);

            $entries = $field->type->entryRules($field);

            if ($entries !== null) {
                $rules['answers.'.$field->key.'.*'] = $entries;
            }
        }

        return $rules;
    }

    /**
     * The messages those rules speak with.
     *
     * Keyed per field so somebody reads "Waarom vraag je dit aan is verplicht"
     * rather than "answers.reden is verplicht" — the label is the only name for
     * the box they were looking at.
     *
     * @return array<string, string>
     */
    public function messagesFor(Form $form): array
    {
        $messages = [];

        foreach ($form->fields as $field) {
            foreach (['required', 'in', 'numeric', 'date', 'array'] as $rule) {
                $messages['answers.'.$field->key.'.'.$rule] = __('validation.'.$rule, [
                    'attribute' => $field->label,
                ]);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function handle(Form $form, ?User $submitter, array $answers, bool $viaLink = false): FormSubmission
    {
        /*
         * Checked here and not only in the policy, because the public link has
         * no policy to run: the token says "you may fill this in", and whether
         * the form is still open is a different question that both doors have
         * to ask.
         */
        if (! $form->acceptsAnswers()) {
            throw new RuntimeException(__('forms.errors.closed'));
        }

        $submission = DB::transaction(function () use ($form, $submitter, $answers, $viaLink): FormSubmission {
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'submitted_by' => $submitter?->id,
                'via_link' => $viaLink,
            ]);

            foreach ($form->fields as $position => $field) {
                FormAnswer::create([
                    'form_submission_id' => $submission->id,
                    'form_field_id' => $field->id,
                    'field_key' => $field->key,
                    'question' => $field->label,
                    'type' => $field->type,
                    'value' => $field->type->normalise($answers[$field->key] ?? null),
                    'position' => $position,
                ]);
            }

            return $submission;
        });

        /*
         * Announced after the transaction rather than inside it. Both listeners
         * go and read the submission back — one of them from a queue worker in
         * another process — and a row that is not committed yet is a row they
         * cannot find.
         */
        FormSubmitted::dispatch($submission->load(['form.author', 'answers', 'submitter']));

        return $submission;
    }

    /**
     * The empty answer map a fill screen starts from.
     *
     * Built from the fields so the browser never has to guess that a
     * multiple-choice box begins as a list and a tickbox as false — the same
     * knowledge normalise() applies on the way back in.
     *
     * @return array<string, mixed>
     */
    public function blankAnswers(Form $form): array
    {
        return $form->fields
            ->mapWithKeys(fn (FormField $field): array => [
                $field->key => match (true) {
                    $field->type->isMultiple() => [],
                    $field->type === FormFieldType::Boolean => false,
                    default => '',
                },
            ])
            ->all();
    }
}
