<?php

use App\Actions\Workflows\PruneWorkflowRuns;
use App\Enums\SystemRole;
use App\Enums\WorkflowBranch;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepKind;
use App\Features\Contracts;
use App\Features\Tickets;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Workflows\WorkflowRegistry;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

it('hands the builder the questions a form asks, under the keys they arrive as', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $form = Form::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'title' => 'Storingsmelding',
    ]);

    FormField::factory()->for($form)->create([
        'key' => 'wat_gaat_er_fout',
        'label' => 'Wat gaat er fout?',
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        /*
         * The key without punctuation and the question with it. This is the one
         * trigger whose variables the register cannot describe — the answers
         * arrive under keys the form invented — so the picker is built from
         * this, and somebody guessing at the spelling is what put a literal
         * {{ trigger.answers.wat_gaat_er_fout? }} in a ticket title.
         */
        ->assertInertia(fn ($page) => $page
            ->where('forms.0.title', 'Storingsmelding')
            ->where('forms.0.fields.0.key', 'wat_gaat_er_fout')
            ->where('forms.0.fields.0.label', 'Wat gaat er fout?'));
});

it('lists a workspace his workflows without drawing any of them', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    WorkflowStep::factory()->for($workflow)->create();

    $this->actingAs($admin)
        ->get(route('workflows.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workflows')
            ->where('workflows.0.stepCount', 1)
            // The steps themselves stay behind. This screen draws a name and a
            // count, and sending every step of every workflow to it is a page
            // that gets slower each time somebody writes another one.
            ->missing('workflows.0.steps')
            /*
             * Only the triggers, because the only thing built here is a new
             * workflow's first question. Counted against what this workspace is
             * offered rather than against a number: a screen whose test has to
             * be edited every time a trigger is written is a test that says
             * nothing.
             */
            ->has('triggers', count(app(WorkflowRegistry::class)->toArray($workspace)['triggers']))
            ->missing('catalogue')
        );
});

it('tells the list when each workflow last went off', function () {
    [$admin, $workspace] = workflowBeheerder();

    $ran = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $never = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $lately = now()->subHour();

    WorkflowRun::factory()->for($ran)->create(['created_at' => now()->subDays(3)]);
    WorkflowRun::factory()->for($ran)->create(['created_at' => $lately]);

    // The newest of the two, not the last one written down: a run that was
    // backfilled after the fact would otherwise read as the most recent.
    $this->actingAs($admin)
        ->get(route('workflows.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Newest first, so the one that has never run leads the list.
            ->where('workflows.0.id', $never->id)
            ->where('workflows.0.lastRunAt', null)
            ->where('workflows.1.id', $ran->id)
            ->where('workflows.1.lastRunAt', $lately->toIso8601String()));
});

it('shows a beheerder the builder with everything it can be built from', function () {
    [$admin, $workspace] = workflowBeheerder();

    $registry = app(WorkflowRegistry::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workflow-edit')
            ->has('catalogue.triggers', count($registry->toArray($workspace)['triggers']))
            ->has('catalogue.actions', count($registry->actions()))
            // The operators come down grouped, with whether each of them has a
            // right-hand side at all. Both were written out in the screen until
            // there were twenty of them, and a list in the screen is a list
            // that goes stale without saying so.
            ->has('grammar.operatorGroups', 4)
            ->where('grammar.operatorGroups.0.operators.0.value', 'equals')
            ->where('grammar.operatorGroups.0.operators.0.needsValue', true)
            // The fields come down with them: a builder that had to ask for
            // them would drift from what the runner reads.
            ->has('catalogue.triggers.0.fields')
            ->has('catalogue.triggers.0.provides')
            // And the pickers, which only this screen has any use for.
            ->has('channels')
            ->has('members')
        );
});

/*
 * Everything the catalogue hands over is complete enough to draw. A count says
 * nothing about that: a trigger registered without a description or without
 * fields would keep every count right and leave a blank half-form on screen.
 */
it('describes every trigger and action it offers well enough to draw', function () {
    [$admin, $workspace] = workflowBeheerder();

    $catalogue = app(WorkflowRegistry::class)->toArray($workspace);

    foreach ([...$catalogue['triggers'], ...$catalogue['actions']] as $item) {
        expect($item['key'])->toBeString()->not->toBe('')
            ->and($item['label'])->toBeString()->not->toBe('')
            ->and($item['description'])->toBeString()->not->toBe('')
            ->and($item['fields'])->toBeArray()
            ->and($item['provides'])->toBeArray();

        foreach ($item['fields'] as $field) {
            // The control the builder has to draw, and whether a variable may
            // go in it. Both come from here and from nowhere else.
            expect($field['key'])->toBeString()->not->toBe('')
                ->and($field['label'])->toBeString()->not->toBe('')
                ->and($field['type'])->toBeString()->not->toBe('')
                ->and($field['acceptsVariables'])->toBeBool();
        }
    }
});

/*
 * And a trigger this workspace could never use is not offered at all. Somebody
 * picking "contract getekend" where no signatures are asked for saves a
 * workflow, switches it on, and waits for something that has nothing to listen
 * to — the listener asks the same question before it starts anything.
 */
it('leaves out the triggers this workspace has switched off', function () {
    [$admin, $workspace] = workflowBeheerder();

    Feature::for($workspace)->deactivate(Contracts::class);

    $this->actingAs($admin)
        ->get(route('workflows.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('triggers', fn (Collection $triggers): bool => $triggers
                ->pluck('key')
                ->doesntContain('contract-signed'))
        );

    Feature::for($workspace)->activate(Contracts::class);

    $this->actingAs($admin)
        ->get(route('workflows.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('triggers', fn (Collection $triggers): bool => $triggers
                ->pluck('key')
                ->contains('contract-signed'))
        );
});

/*
 * Its own trigger is the exception, and it has to be: a workflow written while
 * contracts were on is still pointed at one. Drop it from the picker and the
 * browser falls back to whatever sits at the top, so opening the workflow and
 * pressing save would quietly point it somewhere else.
 */
it('keeps a workflow his own trigger in the picker after the feature goes off', function () {
    [$admin, $workspace] = workflowBeheerder();

    Feature::for($workspace)->activate(Contracts::class);

    $workflow = Workflow::factory()->triggeredBy('contract-signed', [])->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    Feature::for($workspace)->deactivate(Contracts::class);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('catalogue.triggers', fn (Collection $triggers): bool => $triggers
                ->pluck('key')
                ->contains('contract-signed'))
            // The other seven are gone all the same: this is one workflow's
            // exception, not the feature coming back.
            ->where('catalogue.triggers', fn (Collection $triggers): bool => $triggers
                ->pluck('key')
                ->doesntContain('contract-declined'))
        );
});

it('keeps one workspace out of another his builder', function () {
    [$admin] = workflowBeheerder();
    [$otherAdmin, $elsewhere] = workflowBeheerder();

    $theirs = Workflow::factory()->create([
        'workspace_id' => $elsewhere->id,
        'created_by' => $otherAdmin->id,
    ]);

    $this->actingAs($admin)->get(route('workflows.edit', $theirs))->assertNotFound();
});

it('opens a new workflow straight into the builder', function () {
    [$admin, $workspace] = workflowBeheerder();

    $this->actingAs($admin)
        ->post(route('workflows.store'), [
            'name' => 'Storingsmelder',
            'trigger_type' => 'message-keyword',
        ])
        // A workflow with a name and nothing else does nothing at all, so the
        // list is never where somebody wanted to end up.
        ->assertRedirect(route('workflows.edit', $workspace->workflows()->first()));
});

it('keeps an ordinary member out of the builder', function () {
    [, $workspace] = workflowBeheerder();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    $this->actingAs($member)->get(route('workflows.index'))->assertForbidden();
});

it('has no builder at all in a workspace that switched workflows off', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    Feature::for($workspace)->deactivate(WorkflowsFeature::class);

    $this->actingAs($admin)->get(route('workflows.index'))->assertForbidden();
});

it('creates a workflow switched off', function () {
    [$admin, $workspace] = workflowBeheerder();

    $this->actingAs($admin)
        ->post(route('workflows.store'), [
            'name' => 'Storingsmelder',
            'trigger_type' => 'message-keyword',
        ])
        ->assertRedirect();

    $workflow = $workspace->workflows()->first();

    expect($workflow)->not->toBeNull()
        ->and($workflow->isEnabled())->toBeFalse()
        ->and($workflow->created_by)->toBe($admin->id);
});

it('gives a webhook workflow a URL the moment it becomes one', function () {
    [$admin, $workspace] = workflowBeheerder();

    $this->actingAs($admin)->post(route('workflows.store'), [
        'name' => 'Van buiten',
        'trigger_type' => 'webhook',
    ]);

    expect($workspace->workflows()->first()->webhookUrl())->not->toBeNull();
});

it('saves the name a workflow his messages are signed with', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)->put(route('workflows.update', $workflow), [
        'name' => 'Storingsmelder',
        'bot_name' => 'Storingsdienst',
        'trigger_type' => 'message-keyword',
        // The words are what this trigger cannot do without, and saving is
        // where that is now said — see ValidateConfiguration.
        'trigger_config' => ['keywords' => ['storing']],
        'steps' => [],
    ]);

    expect($workflow->fresh()->botName())->toBe('Storingsdienst');
});

it('reads an emptied box as the workflow his own name again', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'Storingsmelder',
        'bot_name' => 'Storingsdienst',
    ]);

    $this->actingAs($admin)->put(route('workflows.update', $workflow), [
        'name' => 'Storingsmelder',
        // A box holding nothing but spaces is a box somebody cleared, and the
        // middleware has turned it into null by the time the rules see it.
        'bot_name' => '   ',
        'trigger_type' => 'message-keyword',
        'trigger_config' => ['keywords' => ['storing']],
        'steps' => [],
    ]);

    expect($workflow->fresh())
        ->bot_name->toBeNull()
        ->botName()->toBe('Storingsmelder');
});

it('refuses a name longer than a message can carry', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Storingsmelder',
            'bot_name' => str_repeat('a', 81),
            'trigger_type' => 'message-keyword',
            'trigger_config' => [],
            'steps' => [],
        ])
        ->assertSessionHasErrors('bot_name');
});

it('sends the builder the stored name rather than what stands in for it', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'Storingsmelder',
        'bot_name' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertInertia(fn ($page) => $page
            /*
             * Empty, not filled in with the workflow's name. Sending what the
             * messages are signed with would turn the fallback into a choice
             * the first time anybody saved the screen, after which renaming the
             * workflow would quietly stop renaming its messages.
             */
            ->where('workflow.botName', null)
        );
});

it('leaves an existing URL alone when the workflow is saved again', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->triggeredBy('webhook')->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $token = $workflow->regenerateWebhookToken();

    $this->actingAs($admin)->put(route('workflows.update', $workflow), [
        'name' => 'Andere naam',
        'trigger_type' => 'webhook',
        'trigger_config' => [],
        'steps' => [],
    ]);

    // Re-minting on every save would break every integration each time
    // somebody fixed a typo.
    expect($workflow->fresh()->webhook_token)->toBe($token);
});

it('saves a workflow whole, steps and all', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $channel = channelWithMember($workspace, $admin);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Welkom',
            'trigger_type' => 'channel-join',
            'trigger_config' => ['channel_id' => $channel->id],
            'steps' => [
                [
                    'kind' => 'action',
                    'action_type' => 'send-channel-message',
                    'config' => ['channel_id' => $channel->id, 'body' => 'Hoi!'],
                    'condition' => null,
                ],
                [
                    'kind' => 'action',
                    'action_type' => 'add-reaction',
                    'config' => ['emoji' => '👋'],
                    'condition' => [
                        'match' => 'all',
                        'otherwise' => 'stop',
                        'rules' => [
                            [
                                'path' => 'trigger.user.name',
                                'operator' => 'is-not-empty',
                                'value' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $workflow->refresh();

    expect($workflow->name)->toBe('Welkom')
        ->and($workflow->trigger_type)->toBe('channel-join')
        ->and($workflow->steps)->toHaveCount(2)
        // Positions come from the order they arrived in, not from the client.
        ->and($workflow->steps->pluck('position')->all())->toBe([0, 1])
        ->and($workflow->steps[1]->condition['rules'][0]['operator'])->toBe('is-not-empty')
        // The half of a condition that says what it guards: this one is a
        // filter on the rest of the workflow, not on its own step.
        ->and($workflow->steps[1]->condition['otherwise'])->toBe('stop');
});

it('refuses an action nobody has ever built', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Iets',
            'trigger_type' => 'message-keyword',
            'steps' => [['kind' => 'action', 'action_type' => 'verzin-een-kanaal', 'config' => []]],
        ])
        ->assertSessionHasErrors('steps.0.action_type');
});

it('will not switch on a workflow that has no steps', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->patch(route('workflows.toggle', $workflow), ['enabled' => true])
        ->assertStatus(422);

    expect($workflow->fresh()->isEnabled())->toBeFalse();
});

it('switches a workflow with steps on and off again', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    WorkflowStep::factory()->for($workflow)->create();

    $this->actingAs($admin)->patch(route('workflows.toggle', $workflow), ['enabled' => true]);
    expect($workflow->fresh()->isEnabled())->toBeTrue();

    $this->actingAs($admin)->patch(route('workflows.toggle', $workflow), ['enabled' => false]);
    expect($workflow->fresh()->isEnabled())->toBeFalse();
});

it('refuses to touch a workflow belonging to another workspace', function () {
    [$admin] = workflowBeheerder();
    [$otherAdmin, $elsewhere] = workflowBeheerder();

    $theirs = Workflow::factory()->create([
        'workspace_id' => $elsewhere->id,
        'created_by' => $otherAdmin->id,
    ]);

    $this->actingAs($admin)
        ->put(route('workflows.update', $theirs), [
            'name' => 'Gekaapt',
            'trigger_type' => 'message-keyword',
            'steps' => [],
        ])
        ->assertNotFound();

    expect($theirs->fresh()->name)->not->toBe('Gekaapt');
});

it('shows what a workflow has been doing, step by step', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'Melder',
    ]);

    $run = WorkflowRun::factory()->for($workflow)->failed('Dat kanaal bestaat niet meer.')->create([
        'context' => ['trigger' => ['user' => ['name' => 'Pietje']], 'depth' => 1],
    ]);

    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 0,
        'action_type' => 'send-channel-message',
    ]);

    WorkflowStepRun::factory()->skipped()->create([
        'workflow_run_id' => $run->id,
        'position' => 1,
        'action_type' => 'add-reaction',
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workflow-runs')
            ->where('runs.data.0.status', WorkflowRunStatus::Failed->value)
            ->where('runs.data.0.failureReason', 'Dat kanaal bestaat niet meer.')
            ->has('runs.data.0.steps', 2)
            // Named as a person reads it, not by its stored key.
            ->where('runs.data.0.steps.0.action', 'Bericht in een kanaal')
            ->where('runs.data.0.steps.1.status', 'skipped')
            // The context as it stood, minus the runner's own bookkeeping.
            ->where('runs.data.0.context.trigger.user.name', 'Pietje')
            ->missing('runs.data.0.context.depth')
        );
});

it('keeps one workspace out of another his run history', function () {
    [$admin] = workflowBeheerder();
    [$otherAdmin, $elsewhere] = workflowBeheerder();

    $theirs = Workflow::factory()->create([
        'workspace_id' => $elsewhere->id,
        'created_by' => $otherAdmin->id,
    ]);

    $this->actingAs($admin)->get(route('workflows.runs', $theirs))->assertNotFound();
});

it('keeps an ordinary member out of the run history', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    /*
     * The same door as the builder, and it has to be: a run holds the context
     * it walked with — message text, people's names — so a screen that was
     * merely harder to find would be handing that to anybody with the link.
     */
    $this->actingAs($member)->get(route('workflows.runs', $workflow))->assertForbidden();
});

it('says how many rows a loop walked, nought included', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $run = WorkflowRun::factory()->for($workflow)->create();

    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 0,
        'action_type' => WorkflowStepKind::Loop->value,
        'result' => ['count' => 3],
    ]);

    /*
     * A loop that found nothing is the case this line exists for: "nul" is the
     * answer somebody is looking for when a loop appears to have done nothing,
     * and a missing count would read as the loop never having run.
     */
    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 1,
        'action_type' => WorkflowStepKind::Loop->value,
        'result' => [],
    ]);

    // Not a loop, so the number means nothing and is not offered at all.
    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 2,
        'action_type' => 'add-reaction',
        'result' => ['count' => 9],
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('runs.data.0.steps.0.count', 3)
            ->where('runs.data.0.steps.1.count', 0)
            ->where('runs.data.0.steps.2.count', null)
        );
});

it('names an action the register no longer knows by the key it was stored as', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $run = WorkflowRun::factory()->for($workflow)->create();

    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 0,
        'action_type' => 'stuur-een-duif',
    ]);

    /*
     * An action taken out of the application since the run happened. The line
     * still has to say which one it was: a history that quietly renamed the
     * step to nothing would be worse than one that shows a key nobody
     * recognises, because the key is at least searchable.
     */
    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('runs.data.0.steps.0.action', 'stuur-een-duif')
        );
});

it('shows a waiting run the moment it gives up waiting', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $deadline = now()->addDays(3);

    WorkflowRun::factory()->for($workflow)->waiting()->create([
        'resume_at' => $deadline,
    ]);

    /*
     * The one fact that makes a waiting run readable rather than worrying: a
     * run that has sat there for a week looks stuck until the screen says when
     * it stops sitting there.
     */
    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('runs.data.0.status', WorkflowRunStatus::Waiting->value)
            ->where('runs.data.0.resumeAt', $deadline->toIso8601String())
            ->where('runs.data.0.finishedAt', null)
        );
});

it('puts the newest run first and pages the rest', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    // One more than a page holds, so both halves of the promise are exercised.
    $runs = WorkflowRun::factory()->for($workflow)->count(26)->create();

    $newest = $runs->last();

    /*
     * Newest first, because the question this screen answers is almost always
     * about the run that just happened. Ordered by id rather than by a
     * timestamp: runs created within the same second are ordinary on a queue,
     * and a tie there would make the top of the list arbitrary.
     */
    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('runs.data.0.id', $newest->id)
            ->has('runs.data', 25)
            ->where('runs.total', 26)
        );
});

it('clears out runs that have been finished long enough, and leaves waiting ones', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $old = WorkflowRun::factory()->for($workflow)->succeeded()->create([
        'created_at' => now()->subMonth(),
    ]);
    $recent = WorkflowRun::factory()->for($workflow)->succeeded()->create();

    // A delay may be a fortnight long; clearing it would leave a workflow
    // permanently half-done with nothing to say why.
    $waiting = WorkflowRun::factory()->for($workflow)->waiting('+1 hour')->create([
        'created_at' => now()->subMonth(),
    ]);

    $removed = app(PruneWorkflowRuns::class)->handle();

    expect($removed)->toBe(1)
        ->and(WorkflowRun::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(WorkflowRun::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(WorkflowRun::query()->whereKey($waiting->id)->exists())->toBeTrue();
});

it('offers the builder in the navigation only where it can be reached', function () {
    [$admin, $workspace] = workflowBeheerder();

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageWorkflows', true));

    Feature::for($workspace)->deactivate(WorkflowsFeature::class);

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageWorkflows', false));
});

it('saves a fork with both its lanes, numbered in reading order', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $channel = channelWithMember($workspace, $admin);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Splitsing',
            'trigger_type' => 'channel-join',
            'trigger_config' => ['channel_id' => $channel->id],
            'steps' => [
                [
                    'kind' => 'branch',
                    'condition' => [
                        'match' => 'all',
                        'otherwise' => 'skip',
                        'rules' => [['path' => 'trigger.user.name', 'operator' => 'is-not-empty', 'value' => '']],
                    ],
                    'branches' => [
                        'then' => [[
                            'kind' => 'action',
                            'action_type' => 'send-channel-message',
                            'config' => ['channel_id' => $channel->id, 'body' => 'Hoi!'],
                        ]],
                        'else' => [[
                            'kind' => 'action',
                            'action_type' => 'add-reaction',
                            'config' => ['emoji' => '👋'],
                        ]],
                    ],
                ],
                ['kind' => 'action', 'action_type' => 'add-reaction', 'config' => ['emoji' => '✅']],
            ],
        ])
        ->assertRedirect();

    $steps = $workflow->fresh()->steps;

    expect($steps)->toHaveCount(4);

    [$fork, $then, $else, $after] = $steps->all();

    /*
     * Reading order: the fork, its then-lane, its else-lane, then whatever
     * follows it. Unique across the whole workflow, which is what keeps
     * {{ steps.3.… }} pointing at one particular step.
     */
    expect($steps->pluck('position')->all())->toBe([0, 1, 2, 3])
        ->and($fork->kind)->toBe(WorkflowStepKind::Branch)
        ->and($then->parent_step_id)->toBe($fork->id)
        ->and($then->branch)->toBe(WorkflowBranch::Then)
        ->and($else->branch)->toBe(WorkflowBranch::Else)
        // What follows the fork hangs under nothing: the lanes come back
        // together, and this step is where they meet.
        ->and($after->parent_step_id)->toBeNull();
});

it('refuses a fork inside a lane', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Te diep',
            'trigger_type' => 'channel-join',
            'steps' => [[
                'kind' => 'branch',
                'branches' => [
                    'then' => [['kind' => 'branch', 'branches' => ['then' => [], 'else' => []]]],
                    'else' => [],
                ],
            ]],
        ])
        // Not because the runner could not walk it — it could — but because two
        // levels of lanes in one column stop being a picture of anything.
        ->assertSessionHasErrors('steps.0.branches.then.0.kind');

    expect($workflow->fresh()->steps)->toHaveCount(0);
});

it('takes a fork with no lanes at all as a fork that does nothing', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Leeg',
            'trigger_type' => 'channel-join',
            'steps' => [['kind' => 'branch', 'branches' => ['then' => [], 'else' => []]]],
        ])
        ->assertRedirect();

    // A fork on its own is what somebody has just added and not yet filled in.
    // Refusing it would mean a builder that cannot be saved halfway.
    expect($workflow->fresh()->steps)->toHaveCount(1);
});

it('shows a run as the shape it walked, lanes and all', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $run = WorkflowRun::factory()->for($workflow)->create();

    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 0,
        'action_type' => 'branch',
        'result' => ['lane' => 'else'],
    ]);

    WorkflowStepRun::factory()->create([
        'workflow_run_id' => $run->id,
        'position' => 2,
        'action_type' => 'add-reaction',
        'branch' => WorkflowBranch::Else,
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.runs', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A fork is not in the register, so it needs a name of its own
            // rather than the word in its column.
            ->where('runs.data.0.steps.0.action', 'Splitsing')
            ->where('runs.data.0.steps.0.lane', 'Zo niet')
            // Which lane the step stood in, read off the run's own row so that
            // editing the workflow afterwards cannot redraw the picture.
            ->where('runs.data.0.steps.1.branch', 'else')
            ->where('runs.data.0.steps.1.branchLabel', 'Zo niet')
            ->where('runs.data.0.steps.1.actionType', 'add-reaction')
        );
});

it('hands the builder a fork as a shape rather than as rows', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $fork = WorkflowStep::factory()->for($workflow)->at(0)->forking()->create();

    WorkflowStep::factory()->for($workflow)->at(1)->inLane($fork, WorkflowBranch::Then)
        ->doing('add-reaction', ['emoji' => '👋'])->create();

    WorkflowStep::factory()->for($workflow)->at(2)->create();

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Two at the top — the fork and what follows it — with the lane
            // hanging under the first. Handing the builder a flat list would
            // leave it to work the nesting out from parent ids, which is the
            // sort of thing two sides of a wire come to disagree about.
            ->has('workflow.steps', 2)
            ->where('workflow.steps.0.kind', 'branch')
            ->has('workflow.steps.0.branches.then', 1)
            ->has('workflow.steps.0.branches.else', 0)
            ->where('workflow.steps.0.branches.then.0.actionType', 'add-reaction')
            ->where('workflow.steps.1.branches', null)
            // What the header counts is every block, lanes included.
            ->where('workflow.stepCount', 3)
            // The lane's own names come from the enum, like every other word
            // the condition editor offers.
            ->where('grammar.branches.then', 'Als het klopt')
        );
});

it('saves a loop with its body, numbered in reading order', function () {
    [$admin, $workspace] = workflowBeheerder();

    Feature::for($workspace)->activate(Tickets::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $channel = channelWithMember($workspace, $admin);

    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Nachtploeg',
            'trigger_type' => 'channel-join',
            'trigger_config' => ['channel_id' => $channel->id],
            'steps' => [
                [
                    'kind' => 'loop',
                    'config' => ['source' => 'overdue-tickets'],
                    'branches' => [
                        'then' => [[
                            'kind' => 'action',
                            'action_type' => 'send-channel-message',
                            'config' => [
                                'channel_id' => $channel->id,
                                'body' => 'Nog open: {{ item.ticket.title }}',
                            ],
                        ]],
                        'else' => [],
                    ],
                ],
                ['kind' => 'action', 'action_type' => 'add-reaction', 'config' => ['emoji' => '✅']],
            ],
        ])
        ->assertRedirect();

    $steps = $workflow->fresh()->steps;

    $loop = $steps->firstWhere('kind', WorkflowStepKind::Loop);
    $body = $steps->firstWhere('parent_step_id', $loop?->id);
    $after = $steps->last();

    expect($steps)->toHaveCount(3)
        ->and($loop->position)->toBe(0)
        // A loop's body is its then lane — the same place a fork keeps one, so
        // nothing between the screen and the database learned a third shape.
        ->and($body->position)->toBe(1)
        ->and($body->branch)->toBe(WorkflowBranch::Then)
        ->and($loop->setting('source'))->toBe('overdue-tickets')
        // And what follows the loop hangs under nothing.
        ->and($after->parent_step_id)->toBeNull()
        ->and($after->position)->toBe(2);
});

it('refuses a loop with no list to walk', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $channel = channelWithMember($workspace, $admin);

    // A loop with no source would walk nothing and read as though it walked
    // something, which is the one way a loop can be quietly wrong.
    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Nachtploeg',
            'trigger_type' => 'channel-join',
            'trigger_config' => ['channel_id' => $channel->id],
            'steps' => [
                ['kind' => 'loop', 'config' => [], 'branches' => ['then' => [], 'else' => []]],
            ],
        ])
        ->assertSessionHasErrors('steps.0.config.source');
});

it('refuses a loop inside a lane', function () {
    [$admin, $workspace] = workflowBeheerder();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
    ]);

    $channel = channelWithMember($workspace, $admin);

    /*
     * A loop inside a loop is fifty rows times fifty rows, and a loop inside a
     * fork's lane is a picture that stops being one. The runner and the storage
     * would both take either.
     */
    $this->actingAs($admin)
        ->put(route('workflows.update', $workflow), [
            'name' => 'Splitsing',
            'trigger_type' => 'channel-join',
            'trigger_config' => ['channel_id' => $channel->id],
            'steps' => [
                [
                    'kind' => 'branch',
                    'condition' => [
                        'match' => 'all',
                        'otherwise' => 'skip',
                        'rules' => [['path' => 'trigger.user.name', 'operator' => 'is-not-empty', 'value' => '']],
                    ],
                    'branches' => [
                        'then' => [[
                            'kind' => 'loop',
                            'config' => ['source' => 'overdue-tickets'],
                            'branches' => ['then' => [], 'else' => []],
                        ]],
                        'else' => [],
                    ],
                ],
            ],
        ])
        ->assertSessionHasErrors('steps.0.branches.then.0.kind');
});
