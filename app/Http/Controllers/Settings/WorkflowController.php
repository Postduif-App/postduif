<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\StoreAvatar;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\AttachmentType;
use App\Enums\WorkflowBranch;
use App\Enums\WorkflowConditionMatch;
use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionOutcome;
use App\Enums\WorkflowStepKind;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Workflows\Actions\HttpRequest;
use App\Workflows\Triggers\SlashCommandTrigger;
use App\Workflows\Triggers\WebhookTrigger;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowTrigger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing the things a workspace does by itself.
 *
 * What a trigger and an action need is never described here. It comes out of
 * the register, which is what the runner reads too — a form that kept its own
 * list of fields would drift from what the runner expects, and the first anybody
 * would hear of it is a run that failed at three in the morning.
 */
class WorkflowController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Enough that nobody sensible runs into it, low enough that a workspace
     * cannot quietly acquire a hundred things acting on its own.
     */
    private const MAX_WORKFLOWS = 50;

    /** The same idea one level down: a list of steps stays a list. */
    private const MAX_STEPS = 25;

    /** And one level down again: a condition stays a sentence. */
    private const MAX_RULES = 5;

    /** How many paths out of one remembered answer the picker will offer. */
    private const MAX_SAMPLED_PATHS = 60;

    public function index(Request $request, WorkflowRegistry $registry): Response
    {
        // manageWorkflows rather than the trait's default manage: it asks the
        // same role question and the feature question in one breath, so a
        // workspace with workflows switched off has no such screen.
        $workspace = $this->currentWorkspace($request, 'manageWorkflows');

        return Inertia::render('settings/workflows', [
            /*
             * A line each: what it is called, what sets it off, how big it is,
             * whether it is on. Not the steps — the list does not draw them any
             * more, and sending every step of every workflow to a screen that
             * shows a name and a count is a page that gets slower every time
             * somebody writes another workflow.
             */
            'workflows' => $workspace->workflows()
                ->withCount('steps')
                ->with('owner:id,name')
                ->latest('id')
                ->get()
                ->map(fn (Workflow $workflow): array => [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'description' => $workflow->description,
                    'triggerType' => $workflow->trigger_type,
                    'enabled' => $workflow->isEnabled(),
                    'owner' => $workflow->owner?->name,
                    'stepCount' => $workflow->steps_count,
                ])
                ->all(),

            /*
             * Only the triggers, because the only thing built on this screen is
             * a new workflow's first question. The actions belong to the builder
             * and travel with it — see edit().
             */
            'triggers' => $registry->toArray()['triggers'],
        ]);
    }

    /**
     * The builder, for one workflow.
     *
     * Its own screen rather than a panel in the list. Writing a workflow is
     * several sittings' work — trigger, then steps, then the words in them —
     * and the whole vocabulary it needs is more than the list has any use for.
     */
    public function edit(Request $request, Workflow $workflow, WorkflowRegistry $registry): Response
    {
        $this->authorizeWorkflow($request, $workflow);

        $workflow->load(['steps', 'owner:id,name']);

        $workspace = $workflow->workspace;

        return Inertia::render('settings/workflow-edit', [
            'workflow' => $this->present($workflow),

            /*
             * The whole vocabulary in one go: every trigger and action with its
             * fields and the variables it offers. Sent up front rather than
             * fetched per selection, because it is small and because a builder
             * that has to wait for a round trip before it can draw the second
             * half of a form is a builder that feels broken.
             */
            'catalogue' => $registry->toArray(),

            /*
             * The condition's own vocabulary, from the enums rather than from a
             * list in the builder. Same reasoning as the register above: a
             * screen that spells out its own operators is a screen that keeps
             * offering one after it has been taken out of the runner.
             */
            'grammar' => [
                'operators' => WorkflowConditionOperator::options(),
                'matches' => WorkflowConditionMatch::options(),
                'outcomes' => WorkflowConditionOutcome::options(),
                'branches' => WorkflowBranch::options(),
            ],

            /*
             * What the last run found where an action can only promise "some
             * JSON" — see samplesFor(). The register cannot describe the shape
             * of somebody else's API, and this is the one honest source for it.
             */
            'samples' => $this->samplesFor($workflow),

            // What a channel or member picker chooses from. Names only: this
            // screen never needs anything else about them.
            'channels' => $workspace->channels()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),
            'members' => $workspace->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->all(),

            /*
             * And what a form picker chooses from. Every form of this workspace
             * rather than only the open ones: a workflow is written to outlive
             * the moment, and a form that is closed today may be reopened
             * tomorrow — hiding it here would silently empty the one setting
             * the trigger cannot do without.
             */
            /*
             * The questions come with them, and that is the point of sending
             * more than a name here. The answers to a form arrive under keys
             * the form itself invented, so this is the only trigger whose
             * variables cannot be described by the register — see
             * FormSubmittedTrigger::provides. Sending them lets the builder
             * offer {{ trigger.answers.reden }} in the picker rather than
             * leaving somebody to guess at the spelling, which is exactly the
             * guess that produced a title reading "{{ trigger.answers.wat_gaat_er_fout? }}".
             */
            'forms' => $workspace->forms()
                ->with('fields:id,form_id,key,label,position')
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Form $form): array => [
                    'id' => $form->id,
                    'title' => $form->title,
                    'fields' => $form->fields
                        ->map(fn (FormField $field): array => [
                            'key' => $field->key,
                            'label' => $field->label,
                        ])
                        ->values()
                        ->all(),
                ])
                ->all(),
        ]);
    }

    /**
     * Give this workflow a face, or replace the one it has.
     *
     * Through the same StoreAvatar a member's photograph goes through — squared
     * and shrunk on the way in, stored on the private disk, served through a
     * route. The validation is the workspace logo's, down to the refusal of an
     * SVG: a bot avatar is drawn beside messages in a channel, and a script in a
     * costume is no more welcome there than anywhere else.
     */
    public function storeAvatar(Request $request, Workflow $workflow, StoreAvatar $storeAvatar): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $request->validate([
            'avatar' => [
                'required',
                'image',
                'max:2048',
                'mimetypes:'.implode(',', AttachmentType::Images->mimeTypes()),
            ],
        ], [
            'avatar.mimetypes' => __('requests.image.type'),
        ]);

        $storeAvatar->handle($workflow, $request->file('avatar'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workflows.screen.avatar_saved')]);

        return back();
    }

    /**
     * Take it away again.
     *
     * What is left is the mark the browser draws for a bot with no picture,
     * which is what every bot message showed before this existed — so removing
     * one is a step back to the default rather than a message with a hole in it.
     */
    public function destroyAvatar(Request $request, Workflow $workflow, StoreAvatar $storeAvatar): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        $storeAvatar->remove($workflow);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workflows.screen.avatar_removed')]);

        return back();
    }

    public function store(Request $request, WorkflowRegistry $registry): RedirectResponse
    {
        // manageWorkflows rather than the trait's default manage: it asks the
        // same role question and the feature question in one breath, so a
        // workspace with workflows switched off has no such screen.
        $workspace = $this->currentWorkspace($request, 'manageWorkflows');

        abort_if(
            $workspace->workflows()->count() >= self::MAX_WORKFLOWS,
            422,
            __('workflows.screen.too_many', ['count' => self::MAX_WORKFLOWS]),
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'trigger_type' => ['required', 'string', Rule::in(array_keys($registry->triggers()))],
        ]);

        $workflow = $workspace->workflows()->create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->mintWebhookTokenIfNeeded($workflow, $registry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('workflows.screen.created'),
        ]);

        /*
         * Straight into the builder rather than back to the list. A workflow
         * with a name and nothing else does nothing at all, so the list is
         * never where somebody wanted to end up — the next thing they have to
         * do is write the steps.
         */
        return to_route('workflows.edit', $workflow);
    }

    /**
     * Save a workflow whole: its name, its trigger, and every step at once.
     *
     * One endpoint rather than one per step, because a workflow is only
     * coherent as a whole — step three reads what step one produced, and a
     * half-saved row of steps is a workflow that means something nobody wrote.
     */
    public function update(
        Request $request,
        Workflow $workflow,
        WorkflowRegistry $registry,
    ): RedirectResponse {
        $this->authorizeWorkflow($request, $workflow);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            /*
             * The same ceiling the channel webhooks give their bot name, so the
             * two kinds of automatic message cannot be signed by names of
             * different lengths. Optional: empty means the workflow's name, and
             * the middleware has already turned a box holding nothing but
             * spaces into null by the time this is read.
             */
            'bot_name' => ['nullable', 'string', 'max:80'],
            'trigger_type' => ['required', 'string', Rule::in(array_keys($registry->triggers()))],
            'trigger_config' => ['array'],
            'steps' => ['array', 'max:'.self::MAX_STEPS],

            ...$this->stepRules('steps', $registry, forks: true),
            ...$this->stepRules('steps.*.branches.then', $registry, forks: false),
            ...$this->stepRules('steps.*.branches.else', $registry, forks: false),
        ]);

        /*
         * The ceiling counts the lanes too. Written here rather than as a rule
         * because what has to stay under it is the whole shape, and a rule can
         * only ever see one level of it at a time.
         */
        abort_if(
            $this->countSteps($data['steps'] ?? []) > self::MAX_STEPS,
            422,
            __('workflows.screen.too_many_steps', ['count' => self::MAX_STEPS]),
        );

        $data['trigger_config'] = $this->settleSlashCommand(
            $workflow,
            $data['trigger_type'],
            $data['trigger_config'] ?? [],
        );

        DB::transaction(function () use ($workflow, $data): void {
            $workflow->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'bot_name' => $data['bot_name'] ?? null,
                'trigger_type' => $data['trigger_type'],
                'trigger_config' => $data['trigger_config'] ?? [],
            ]);

            /*
             * Thrown away and written again rather than matched up row by row.
             * The row is what a workflow is — reordering, inserting and
             * removing are the ordinary edits — and reconciling that by id
             * would be a lot of care spent on keeping numbers that nothing
             * points at.
             *
             * What does point at them is the run history, and that survives:
             * a step run keeps its own copy of the position and the action, and
             * its foreign key is nulled rather than cascaded. See the migration.
             */
            $workflow->steps()->delete();

            $this->writeSteps($workflow, $data['steps'] ?? [], parent: null, branch: null, position: 0);
        });

        $this->mintWebhookTokenIfNeeded($workflow->fresh(), $registry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('workflows.screen.saved'),
        ]);

        return back();
    }

    /**
     * Switch one on or off.
     *
     * Its own endpoint rather than a field on update(), because it is the one
     * decision that changes whether the thing acts on the workspace at all —
     * see Workflow::enable(), which is a method for the same reason.
     */
    public function toggle(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow);

        if ($request->boolean('enabled')) {
            /*
             * A workflow with no steps does nothing, and switching it on would
             * put it in the list as though it did. Said out loud rather than
             * allowed: somebody who switches on an empty workflow is somebody
             * who has not finished writing it.
             */
            abort_if($workflow->steps()->count() === 0, 422, __('workflows.screen.no_steps'));

            $workflow->enable();
        } else {
            $workflow->disable();
        }

        return back();
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorizeWorkflow($request, $workflow, 'delete');

        $workflow->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('workflows.screen.deleted'),
        ]);

        /*
         * To the list rather than back. Deleting is nearly always done from the
         * builder, and back() would be the address of the workflow that has
         * just gone.
         */
        return to_route('workflows.index');
    }

    /**
     * That this workflow is one of the current workspace's, and that this
     * member may touch it.
     *
     * Both, and in that order. The policy already refuses a workflow from a
     * workspace the member cannot manage, but not one from a second workspace
     * they happen to administer as well — and quietly editing the wrong
     * workspace's workflow from this screen is the sort of thing nobody would
     * notice until it ran.
     */
    private function authorizeWorkflow(Request $request, Workflow $workflow, string $ability = 'update'): void
    {
        abort_unless(
            $workflow->workspace_id === $this->currentWorkspace($request, 'manageWorkflows')->id,
            404,
        );

        $this->authorize($ability, $workflow);
    }

    /**
     * What one step in a request has to look like, wherever it sits.
     *
     * Built rather than written out three times: the lanes of a fork hold the
     * same steps the top of the workflow does, and three copies of these rules
     * would be three places for them to drift apart.
     *
     * A lane holds no forks — `forks: false`. The runner would walk a fork
     * inside a lane perfectly well, and the storage has no opinion either; what
     * cannot take it is the reading. Two levels of lanes in one column stops
     * being a picture of anything, and the way to say the third thing is a
     * second fork below the first.
     *
     * @return array<string, list<mixed>>
     */
    private function stepRules(string $prefix, WorkflowRegistry $registry, bool $forks): array
    {
        return [
            "{$prefix}" => ['array', 'max:'.self::MAX_STEPS],
            "{$prefix}.*.kind" => [
                'required',
                $forks
                    ? Rule::enum(WorkflowStepKind::class)
                    : Rule::in([WorkflowStepKind::Action->value]),
            ],
            /*
             * Only an action names one. A fork does nothing, and a fork that
             * arrived carrying an action_type is a screen that has confused the
             * two — better refused than saved as something halfway.
             */
            "{$prefix}.*.action_type" => [
                'exclude_unless:'.$prefix.'.*.kind,'.WorkflowStepKind::Action->value,
                'required',
                'string',
                Rule::in(array_keys($registry->actions())),
            ],
            "{$prefix}.*.config" => ['array'],
            "{$prefix}.*.condition" => ['nullable', 'array'],
            "{$prefix}.*.condition.match" => ['required_with:'.$prefix.'.*.condition', Rule::enum(WorkflowConditionMatch::class)],
            "{$prefix}.*.condition.otherwise" => ['required_with:'.$prefix.'.*.condition', Rule::enum(WorkflowConditionOutcome::class)],
            /*
             * A ceiling on the rules as well as on the steps, and for the same
             * reason. It is also the honest limit of the editor: past a handful
             * of "and"s nobody can say any more what the condition asks.
             */
            "{$prefix}.*.condition.rules" => ['required_with:'.$prefix.'.*.condition', 'array', 'min:1', 'max:'.self::MAX_RULES],
            "{$prefix}.*.condition.rules.*.path" => ['required', 'string', 'max:120'],
            "{$prefix}.*.condition.rules.*.operator" => ['required', Rule::enum(WorkflowConditionOperator::class)],
            "{$prefix}.*.condition.rules.*.value" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * How many steps a request holds, lanes and all.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function countSteps(array $steps): int
    {
        $count = 0;

        foreach ($steps as $step) {
            $count++;

            foreach (['then', 'else'] as $lane) {
                $count += count($step['branches'][$lane] ?? []);
            }
        }

        return $count;
    }

    /**
     * Write one level of the shape, and whatever hangs under it.
     *
     * Positions are handed out in reading order — a fork, then its then-lane,
     * then its else-lane, then whatever follows the fork — and they are unique
     * across the whole workflow. That is what keeps {{ steps.3.channel.id }}
     * meaning one particular step now that a workflow is a shape rather than a
     * row.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function writeSteps(
        Workflow $workflow,
        array $steps,
        ?WorkflowStep $parent,
        ?WorkflowBranch $branch,
        int $position,
    ): int {
        foreach ($steps as $step) {
            $kind = WorkflowStepKind::from($step['kind'] ?? WorkflowStepKind::Action->value);

            $row = $workflow->steps()->create([
                'position' => $position++,
                'kind' => $kind,
                'parent_step_id' => $parent?->id,
                'branch' => $branch,
                // A fork does nothing, and the column is the register's word for
                // what a step does. Its own name is the honest thing to put there.
                'action_type' => $kind === WorkflowStepKind::Branch
                    ? WorkflowStepKind::Branch->value
                    : $step['action_type'],
                'config' => $step['config'] ?? [],
                'condition' => $step['condition'] ?? null,
            ]);

            if ($kind !== WorkflowStepKind::Branch) {
                continue;
            }

            foreach (WorkflowBranch::cases() as $lane) {
                $position = $this->writeSteps(
                    $workflow,
                    $step['branches'][$lane->value] ?? [],
                    parent: $row,
                    branch: $lane,
                    position: $position,
                );
            }
        }

        return $position;
    }

    /**
     * The paths the last run found inside an answer nobody could describe.
     *
     * An action says what it leaves behind, and for nearly all of them that is
     * the whole truth. The HTTP step is the exception: the shape under `json`
     * belongs to whoever answered, so the register can offer the word and
     * nothing below it — which leaves somebody guessing at
     * {{ steps.2.json.order.id }} until they have run it once and read the run
     * screen.
     *
     * So the last run is asked instead. Same trick the webhook trigger uses for
     * the body it was sent, and the same caveat: it is the shape of the last
     * answer, not a promise about the next one. It is offered only where the
     * step at that position is still an HTTP step, so that editing a workflow
     * cannot leave one action wearing another's vocabulary.
     *
     * Only the paths travel, never the values. What came back may be somebody's
     * address; the run screen is where that is read, deliberately and by
     * somebody who went looking.
     *
     * @return array<string, list<string>> Prefix => the paths found under it.
     */
    private function samplesFor(Workflow $workflow): array
    {
        $positions = $workflow->steps
            ->filter(fn (WorkflowStep $step): bool => $step->action_type === HttpRequest::key())
            ->pluck('position');

        if ($positions->isEmpty()) {
            return [];
        }

        $context = $workflow->runs()->latest('id')->value('context');

        if (! is_array($context)) {
            return [];
        }

        $samples = [];

        foreach ($positions as $position) {
            $answer = data_get($context, "steps.{$position}.json");

            if (is_array($answer) && $answer !== []) {
                $samples["steps.{$position}.json"] = $this->pathsIn($answer);
            }
        }

        return $samples;
    }

    /**
     * Every leaf inside a decoded answer, dotted.
     *
     * Capped, because a picker with three hundred entries is a picker nobody
     * scrolls to the bottom of — and an answer that deep is one somebody will
     * read on the run screen anyway.
     *
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function pathsIn(array $value, string $prefix = ''): array
    {
        $found = [];

        foreach ($value as $key => $nested) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($nested) && $nested !== []) {
                $found = [...$found, ...$this->pathsIn($nested, $path)];
            } else {
                $found[] = $path;
            }

            if (count($found) >= self::MAX_SAMPLED_PATHS) {
                return array_slice($found, 0, self::MAX_SAMPLED_PATHS);
            }
        }

        return $found;
    }

    /**
     * A workflow as the builder needs it.
     *
     * The webhook URL is put in by hand rather than left to the model's
     * serialisation, which hides the token on purpose — showing it here is a
     * deliberate act on a screen only a beheerder reaches.
     *
     * @return array<string, mixed>
     */
    private function present(Workflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            /*
             * The stored value rather than what the messages are actually
             * signed with, so an empty box stays empty. Filling it in with the
             * workflow's name would turn the fallback into a choice the first
             * time anybody saved the screen — after which renaming the workflow
             * would quietly stop renaming its messages.
             */
            'botName' => $workflow->bot_name,

            /*
             * The face its messages carry, or null where it has none. A URL
             * rather than the stored path: the path is where the file sits on
             * our disk, and the browser has no business with it.
             */
            'avatarUrl' => $workflow->avatarUrl(),
            'triggerType' => $workflow->trigger_type,
            'triggerConfig' => (object) $workflow->trigger_config,
            'enabled' => $workflow->isEnabled(),
            'owner' => $workflow->owner?->name,
            /*
             * As a shape rather than as the rows it is stored in. The builder
             * draws lanes inside forks, and handing it a flat list would leave
             * it to work out the nesting from parent ids — which is exactly the
             * sort of thing two sides of a wire come to disagree about.
             */
            'steps' => $this->presentSteps($workflow->steps->whereNull('parent_step_id'), $workflow->steps),
            'stepCount' => $workflow->steps->count(),
            'webhookUrl' => $workflow->webhookUrl(),

            /*
             * The last body that arrived, so the variable picker can offer what
             * a sender actually sends rather than the single word "payload".
             * Only ever on this screen — see the model, where it is hidden.
             */
            'webhookPayload' => $workflow->webhook_payload,
        ];
    }

    /**
     * One level of a workflow's shape, with its lanes hanging under it.
     *
     * Every step of the workflow is passed along rather than queried per fork:
     * a workflow holds 25 steps at most, so the whole thing is one read and the
     * nesting is done in memory.
     *
     * @param  Collection<int, WorkflowStep>  $level
     * @param  Collection<int, WorkflowStep>  $all
     * @return list<array<string, mixed>>
     */
    private function presentSteps(Collection $level, Collection $all): array
    {
        return array_values($level->map(fn (WorkflowStep $step): array => [
            'kind' => $step->kind->value,
            'actionType' => $step->action_type,
            'config' => (object) $step->config,
            'condition' => $step->condition,
            'branches' => $step->isBranch()
                ? [
                    'then' => $this->presentSteps($this->laneOf($all, $step, WorkflowBranch::Then), $all),
                    'else' => $this->presentSteps($this->laneOf($all, $step, WorkflowBranch::Else), $all),
                ]
                : null,
        ])->all());
    }

    /**
     * @param  Collection<int, WorkflowStep>  $all
     * @return Collection<int, WorkflowStep>
     */
    private function laneOf(Collection $all, WorkflowStep $fork, WorkflowBranch $branch): Collection
    {
        return $all->filter(
            fn (WorkflowStep $step): bool => $step->parent_step_id === $fork->id && $step->branch === $branch,
        );
    }

    /**
     * Give a webhook workflow a URL the moment it becomes one.
     *
     * Here rather than at creation only, because a workflow can be changed into
     * a webhook one afterwards — and a webhook trigger with no URL is a trigger
     * nothing can ever reach. Existing tokens are left alone: re-minting on
     * every save would break every integration each time somebody fixed a typo.
     */
    /**
     * The trigger config with a slash command tidied up and checked.
     *
     * Here rather than in the rules above because a command has to be
     * normalised before it can be judged: "/Storing" and "storing" are the same
     * name, and a uniqueness rule that compared what was typed would let the
     * second one through. Everything is spelled by the trigger itself — see
     * SlashCommandTrigger — so this only decides *when* to ask.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function settleSlashCommand(Workflow $workflow, string $triggerType, array $config): array
    {
        if ($triggerType !== SlashCommandTrigger::key()) {
            return $config;
        }

        $command = SlashCommandTrigger::normalise((string) ($config['command'] ?? ''));

        throw_unless(
            SlashCommandTrigger::isWellFormed($command),
            ValidationException::withMessages([
                'trigger_config.command' => __('workflows.triggers.slash-command.command.malformed'),
            ]),
        );

        throw_if(
            in_array($command, SlashCommandTrigger::RESERVED, strict: true),
            ValidationException::withMessages([
                'trigger_config.command' => __('workflows.triggers.slash-command.command.reserved', ['command' => $command]),
            ]),
        );

        throw_if(
            SlashCommandTrigger::taken($workflow, $command),
            ValidationException::withMessages([
                'trigger_config.command' => __('workflows.triggers.slash-command.command.taken', ['command' => $command]),
            ]),
        );

        return [...$config, 'command' => $command];
    }

    private function mintWebhookTokenIfNeeded(Workflow $workflow, WorkflowRegistry $registry): void
    {
        /** @var class-string<WorkflowTrigger>|null $trigger */
        $trigger = $registry->trigger($workflow->trigger_type);

        if ($trigger === null || $trigger !== WebhookTrigger::class) {
            return;
        }

        if ($workflow->webhook_token_hash === null) {
            $workflow->regenerateWebhookToken();
        }
    }
}
