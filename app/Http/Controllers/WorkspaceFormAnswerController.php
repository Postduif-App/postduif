<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading what came back.
 *
 * The DM is how somebody hears about a submission; this is where they go
 * looking for the four from last quarter. Two audiences, two shapes — and only
 * one permission, FormPolicy::viewAnswers, because both of them are the same
 * act of reading other people's answers.
 */
class WorkspaceFormAnswerController extends Controller
{
    public function __construct(private readonly BuildChatShell $buildChatShell) {}

    public function index(Request $request, Workspace $workspace, Form $form): Response
    {
        $this->reachable($workspace, $form);

        $form->load('fields');

        $submissions = $form->submissions()
            ->with(['answers', 'submitter:id,name'])
            ->latest('created_at')
            ->get();

        return Inertia::render('chat/form-answers', [
            ...$this->buildChatShell->handle($workspace, $request->user()),

            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'submissions' => $submissions->count(),
            ],

            /*
             * The columns come from the submissions rather than from the form's
             * current fields, so a question deleted last month still has a
             * column for the answers it collected. See columnsFor().
             */
            'columns' => $this->columnsFor($form, $submissions),

            'submissions' => $submissions->map(fn (FormSubmission $submission): array => [
                'id' => $submission->id,
                'when' => $submission->created_at?->toIso8601String(),

                // A name or nothing. The screen turns the nothing into "iemand
                // van buiten"; the controller does not put words in its mouth.
                'who' => $submission->submitter?->name,
                'viaLink' => $submission->via_link,

                'answers' => $submission->answers
                    ->mapWithKeys(fn (FormAnswer $answer): array => [
                        $answer->field_key => $answer->display(),
                    ])
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * The same thing as a file.
     *
     * Streamed rather than built in memory: a form that ran for a year is a
     * long list, and the honest way to hand it over is a row at a time.
     *
     * A BOM goes in front of it, which looks like superstition and is not:
     * without one, Excel reads a UTF-8 CSV as Latin-1 and the first colleague
     * called Renée turns into mojibake in the file somebody archives.
     */
    public function export(Request $request, Workspace $workspace, Form $form): StreamedResponse
    {
        $this->reachable($workspace, $form);

        $form->load('fields');

        $submissions = $form->submissions()
            ->with(['answers', 'submitter:id,name'])
            ->oldest('created_at')
            ->get();

        $columns = $this->columnsFor($form, $submissions);

        $name = str($form->title)->ascii()->slug()->value() ?: 'formulier';

        return response()->streamDownload(function () use ($submissions, $columns): void {
            $handle = fopen('php://output', 'w');

            // Only ever false where output itself is unopenable, which is not a
            // thing to carry on through — the alternative is a download that
            // arrives empty and looks like a form nobody answered.
            if ($handle === false) {
                throw new RuntimeException('De uitvoer is niet te openen om een CSV in te schrijven.');
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('forms.answers_screen.when'),
                __('forms.answers_screen.who'),
                ...array_column($columns, 'label'),
            ]);

            foreach ($submissions as $submission) {
                $answers = $this->answerMap($submission);

                fputcsv($handle, [
                    $submission->created_at?->toDateTimeString() ?? '',
                    $this->nameFor($submission),
                    ...array_map(
                        fn (array $column): string => $answers[$column['key']] ?? '',
                        $columns,
                    ),
                ]);
            }

            fclose($handle);
        }, $name.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',

            // The same header the secret reveal endpoint sets, for the same
            // reason: this is other people's answers, and a proxy has no
            // business keeping a copy.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * One submission's answers, keyed the way a column is looked up.
     *
     * @return array<string, string>
     */
    private function answerMap(FormSubmission $submission): array
    {
        $answers = [];

        foreach ($submission->answers as $answer) {
            $answers[$answer->field_key] = $answer->display();
        }

        return $answers;
    }

    /**
     * Whose submission this was, in the words the file should carry.
     *
     * The screen is handed a null and decides for itself; a CSV has no such
     * luxury — an empty cell in a spreadsheet reads as a mistake rather than as
     * "we do not know", so the sentence is written here.
     */
    private function nameFor(FormSubmission $submission): string
    {
        return $submission->isAnonymous()
            ? __('forms.answers.anonymous')
            : $submission->submitter->name;
    }

    /**
     * Every question these answers were ever given to, in a sensible order.
     *
     * The form's current questions first, in the order somebody reading the
     * form would meet them, then anything the submissions know about that the
     * form no longer asks — a question deleted after people answered it. That
     * second group is why the answers carry their own copy of the wording:
     * without it, these columns would have no heading at all.
     *
     * @param  Collection<int, FormSubmission>  $submissions
     * @return list<array{key: string, label: string}>
     */
    private function columnsFor(Form $form, $submissions): array
    {
        $columns = $form->fields
            ->map(fn ($field): array => ['key' => $field->key, 'label' => $field->label])
            ->all();

        $known = array_column($columns, 'key');

        foreach ($submissions as $submission) {
            foreach ($submission->answers as $answer) {
                if (in_array($answer->field_key, $known, true)) {
                    continue;
                }

                $known[] = $answer->field_key;
                $columns[] = ['key' => $answer->field_key, 'label' => $answer->question];
            }
        }

        return array_values($columns);
    }

    /**
     * The workspace, the scope and the permission, in the order that gives away
     * the least.
     */
    private function reachable(Workspace $workspace, Form $form): void
    {
        abort_unless($form->workspace_id === $workspace->id, 404);

        $this->authorize('viewAnswers', $form);
    }
}
