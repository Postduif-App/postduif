<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Forms\SaveFormFields;
use App\Enums\FormFieldType;
use App\Models\Channel;
use App\Models\Form;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Putting a form together.
 *
 * Inside the chat shell rather than under settings, the same choice the ticket,
 * transfer and secret lists make. Settings is where a workspace is configured
 * once and left alone; writing a form is work somebody does in the middle of
 * their day, next to the conversation it will be announced in — and sending
 * them out of the chat to do it made a routine act feel like administration.
 *
 * The workspace is in the path, so the feature middleware guards the whole
 * group and a workspace with forms switched off has no such URL at all. What is
 * left for the controller is the role and the form itself.
 */
class WorkspaceFormController extends Controller
{
    public function __construct(private readonly BuildChatShell $buildChatShell) {}

    /** Enough for any department, low enough that the list stays a list. */
    private const MAX_FORMS = 50;

    /** A form somebody has to read to the end before answering it. */
    private const MAX_FIELDS = 40;

    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('createForm', $workspace);

        $user = $request->user();

        return Inertia::render('chat/forms', [
            ...$this->buildChatShell->handle($workspace, $user),

            'forms' => $workspace->forms()
                ->withCount(['submissions', 'fields'])
                ->with('author:id,name')
                ->latest('id')
                ->get()
                ->map(fn (Form $form): array => [
                    'id' => $form->id,
                    'title' => $form->title,
                    'description' => $form->description,
                    'author' => $form->author?->name,
                    'state' => match (true) {
                        $form->closed_at !== null => 'closed',
                        $form->isClosed() => 'expired',
                        default => 'open',
                    },
                    'isShared' => $form->isShared(),
                    'submissions' => $form->submissions_count,
                    'fields' => $form->fields_count,

                    // Whether this particular member may open it at all. The
                    // list shows every form in the workspace — a colleague's
                    // included — and the buttons have to follow the policy
                    // rather than the list.
                    'canEdit' => $user->can('update', $form),

                    // Asked separately even though the policy answers both the
                    // same way today: reading other people's answers and
                    // rewriting the questions are different acts, and a screen
                    // that inferred one from the other would quietly hand over
                    // the first the day the second is widened.
                    'canViewAnswers' => $user->can('viewAnswers', $form),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('createForm', $workspace);

        abort_if(
            $workspace->forms()->count() >= self::MAX_FORMS,
            422,
            __('forms.errors.too_many', ['count' => self::MAX_FORMS]),
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
        ]);

        $form = $workspace->forms()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        /*
         * Straight into the builder rather than back to the list. A form with a
         * title and no questions cannot be filled in, so the list is never
         * where somebody wanted to end up.
         */
        return to_route('chat.forms.edit', [$workspace, $form]);
    }

    public function edit(Request $request, Workspace $workspace, Form $form): Response
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('update', $form);

        $form->load(['fields', 'author:id,name']);

        $user = $request->user();

        return Inertia::render('chat/form-edit', [
            ...$this->buildChatShell->handle($workspace, $user),

            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'closesAt' => $form->closes_at?->toDateString(),
                'closedAt' => $form->closed_at?->toIso8601String(),
                'allowsMultipleSubmissions' => $form->allows_multiple_submissions,
                'notifyChannelId' => $form->notify_channel_id,
                'state' => match (true) {
                    $form->closed_at !== null => 'closed',
                    $form->isClosed() => 'expired',
                    default => 'open',
                },
                'submissions' => $form->submissions()->count(),

                /*
                 * The link itself, only for somebody who may share. A member
                 * who may edit the questions but not hand the form to the world
                 * has no business reading the token either — it *is* the
                 * permission, and a page that renders it has given it away.
                 */
                'shareUrl' => $user->can('share', $form) ? $form->publicUrl() : null,
                'isShared' => $form->isShared(),

                'fields' => $form->fields->map(fn ($field): array => [
                    'id' => $field->id,
                    'key' => $field->key,
                    'type' => $field->type->value,
                    'label' => $field->label,
                    'hint' => $field->hint,
                    'required' => $field->required,
                    'options' => $field->options,
                ])->all(),
            ],

            /*
             * The vocabulary of field types, from the enum rather than from a
             * list in the builder — the same reasoning the workflow builder
             * takes its catalogue from the register for.
             */
            'fieldTypes' => array_map(fn (FormFieldType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'takesOptions' => $type->takesOptions(),
            ], FormFieldType::cases()),

            /*
             * No channel list of its own.
             *
             * The shell above already sends one, and a second key of the same
             * name would overwrite it with rows carrying nothing but an id and
             * a name — leaving the sidebar beside this page without the unread
             * counts and types it draws itself from. Both pickers on this
             * screen read the shell's list instead.
             */

            'workspaceSlug' => $workspace->slug,
            'canShare' => $user->can('share', $form),
        ]);
    }

    /**
     * Save the form whole: its settings and every question at once.
     *
     * One endpoint rather than one per field, because a form is only coherent
     * as a whole — a half-saved list of questions is a form nobody wrote. See
     * SaveFormFields for what happens to a question that was dropped.
     */
    public function update(Request $request, Workspace $workspace, Form $form, SaveFormFields $saveFields): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('update', $form);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'closes_at' => ['nullable', 'date', 'after:now'],
            'allows_multiple_submissions' => ['sometimes', 'boolean'],

            /*
             * Where anonymous answers land. Scoped to this workspace in the
             * rule itself rather than checked afterwards: an id from somewhere
             * else must not be storable, and a validator is the only place that
             * refusal reads as a validation error rather than as a 403.
             */
            'notify_channel_id' => [
                'nullable',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],

            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.type' => ['required', Rule::enum(FormFieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.hint' => ['nullable', 'string', 'max:200'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['array', 'max:30'],
            'fields.*.options.*' => ['string', 'max:80'],
        ]);

        $this->guardChoices($data['fields']);

        $form->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'allows_multiple_submissions' => $data['allows_multiple_submissions'] ?? false,
            'notify_channel_id' => $data['notify_channel_id'] ?? null,
        ]);

        $saveFields->handle($form, $data['fields']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.saved')]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('delete', $form);

        $form->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.deleted')]);

        return to_route('chat.forms.index', $workspace);
    }

    /**
     * Stop it early.
     *
     * Recorded as closed_at rather than by moving closes_at, so a card can tell
     * "somebody stopped this" from "the moment passed" — the same two states a
     * poll keeps apart.
     */
    public function close(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('close', $form);

        if ($form->closed_at === null) {
            $form->forceFill(['closed_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.closed')]);

        return back();
    }

    /**
     * Let it run again.
     *
     * Both ways of being shut are undone, not only the one somebody chose: a
     * form whose deadline has passed would close again the instant it reopened
     * if closes_at stayed where it was. A deadline still ahead of us is left
     * alone — that one has not shut anything yet.
     */
    public function reopen(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('reopen', $form);

        $form->forceFill([
            'closed_at' => null,
            'closes_at' => $form->closes_at?->isPast() ? null : $form->closes_at,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.reopened')]);

        return back();
    }

    /**
     * Hand it to the world, or hand it over again.
     *
     * Sharing twice mints a new token and kills the old address — see
     * Form::share(). That is the only honest meaning of "maak een nieuwe link",
     * and it is why this is a POST that replaces rather than one that is
     * idempotent.
     */
    public function share(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('share', $form);

        $form->share();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.shared')]);

        return back();
    }

    public function unshare(Request $request, Workspace $workspace, Form $form): RedirectResponse
    {
        $this->belongsHere($form, $workspace->id);
        $this->authorize('share', $form);

        $form->withdrawLink();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.unshared')]);

        return back();
    }

    /**
     * 404 rather than 403 for a form from another workspace.
     *
     * The settings routes carry no workspace, so this is where the scoping
     * happens. Answering "not yours" would confirm the id exists.
     */
    private function belongsHere(Form $form, int $workspaceId): void
    {
        abort_unless($form->workspace_id === $workspaceId, 404);
    }

    /**
     * A choice question with fewer than two choices is not a choice.
     *
     * Checked here rather than in the rules above because it depends on the
     * type of the same field — a thing Laravel's rule syntax can express only
     * as required_if against every type that takes options, which is a sentence
     * nobody can read six months later.
     *
     * @param  list<array{type: string, options?: list<string>}>  $fields
     */
    private function guardChoices(array $fields): void
    {
        foreach ($fields as $index => $field) {
            $takesOptions = FormFieldType::from($field['type'])->takesOptions();

            $options = array_values(array_filter(
                array_map(trim(...), $field['options'] ?? []),
                fn (string $option): bool => $option !== '',
            ));

            abort_if(
                $takesOptions && count($options) < 2,
                422,
                __('forms.errors.options_required'),
            );

            abort_if(
                ! $takesOptions && $options !== [],
                422,
                __('forms.errors.options_unexpected'),
            );
        }
    }
}
