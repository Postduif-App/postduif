<?php

use App\Actions\Workflows\ResumeWaitingWorkflows;
use App\Actions\Workflows\RunWorkflow;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Events\ContractSigned;
use App\Features\Contracts;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/**
 * Waiting for something to happen instead of waiting on the clock.
 *
 * The scenario the whole story is about, written out once here and then taken
 * apart: "wacht tot dit contract getekend is, en meld het als dat na drie dagen
 * nog niet zo is". Before this it needed two workflows that had to find each
 * other by a detour.
 *
 * @return array{0: Workflow, 1: Contract, 2: ContractSigner, 3: Channel}
 */
function awaitScene(): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Contracts::class);

    $channel = channelWithMember($workspace, $author);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'notify_channel_id' => $channel->id,
    ]);

    $signer = ContractSigner::factory()->for($contract)->create(['name' => 'Jan de Vries']);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'name' => 'Tekenbewaker',
    ]);

    return [$workflow, $contract, $signer, $channel];
}

/** The wait, and a message after it that says which way it went. */
function awaitingRun(Workflow $workflow, Contract $contract, $channel, int $minutes = 4320): WorkflowRun
{
    WorkflowStep::factory()->for($workflow)->at(0)->doing('wait-for-event', [
        'event' => 'contract-signed',
        'minutes' => $minutes,
    ])->create();

    WorkflowStep::factory()->for($workflow)->at(1)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Getekend: {{ steps.0.happened }}',
    ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['depth' => 1, 'trigger' => ['contract' => ['id' => $contract->id]]],
    ]);

    app(RunWorkflow::class)->handle($run);

    return $run->fresh();
}

it('puts a run down to wait for a happening, with a deadline behind it', function () {
    [$workflow, $contract, , $channel] = awaitScene();

    $run = awaitingRun($workflow, $contract, $channel);

    expect($run->status)->toBe(WorkflowRunStatus::Waiting)
        ->and(data_get($run->awaiting, 'event'))->toBe('contract-signed')
        ->and(data_get($run->awaiting, 'record'))->toBe($contract->id)
        // The deadline is not optional: a run waiting for something that never
        // comes is a row nobody ever looks at again.
        ->and($run->resume_at->isFuture())->toBeTrue()
        ->and($channel->messages()->count())->toBe(0)
        // And a line of its own, so the run screen has no silent gap.
        ->and($run->stepRuns)->toHaveCount(1);
});

it('carries on the moment the happening arrives', function () {
    Queue::fake();

    [$workflow, $contract, $signer, $channel] = awaitScene();

    $run = awaitingRun($workflow, $contract, $channel);

    // The contract is signed, and the ordinary listener does what it always
    // does — which now also wakes anybody who was waiting for exactly this.
    $signer->forceFill(['signed_at' => now()])->save();
    $contract->forceFill(['status' => ContractStatus::Completed])->save();

    ContractSigned::dispatch($contract->id, $signer->id);

    $run->refresh();

    expect($run->status)->toBe(WorkflowRunStatus::Running)
        ->and(data_get($run->awaiting, 'happened'))->toBeTrue();

    // The job the resumer put on the queue is what walks the rest of it.
    app(RunWorkflow::class)->handle($run);

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->fresh()->context, 'steps.0.happened'))->toBeTrue()
        ->and($channel->messages()->latest('id')->value('body'))->toContain('ja');
});

it('gives up and goes on when the deadline gets there first', function () {
    [$workflow, $contract, , $channel] = awaitScene();

    $run = awaitingRun($workflow, $contract, $channel, minutes: 60);

    $this->travel(2)->hours();

    // The clock's own sweep, which knows nothing about events: an await is a
    // delay with a shortcut, so this is what ends the ones nobody took.
    app(ResumeWaitingWorkflows::class)->handle();

    app(RunWorkflow::class)->handle($run->fresh());

    $run->refresh();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        // Not a failure and not an error: it is the other half of the question
        // somebody asked.
        ->and(data_get($run->context, 'steps.0.happened'))->toBeFalse()
        ->and($run->awaiting)->toBeNull()
        ->and($channel->messages()->latest('id')->value('body'))->toContain('nee');
});

it('is not woken by the same happening about a different record', function () {
    [$workflow, $contract, , $channel] = awaitScene();

    $run = awaitingRun($workflow, $contract, $channel);

    $other = Contract::factory()->sent()->create([
        'workspace_id' => $contract->workspace_id,
        'created_by' => $contract->created_by,
    ]);
    $otherSigner = ContractSigner::factory()->for($other)->create();

    ContractSigned::dispatch($other->id, $otherSigner->id);

    // Somebody else's contract being signed is not what this run is waiting
    // for, and matching on the event alone would wake every run in the
    // workspace.
    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Waiting);
});

it('is not woken by a workspace it has nothing to do with', function () {
    [$workflow, , , $channel] = awaitScene();

    /*
     * A contract in somebody else's workspace, and a run here that is waiting
     * on exactly that id. Contrived, because a ULID does not repeat — but the
     * record is stored as a plain string and compared as one, and the day a
     * record type has ids that count from one per workspace, this is the check
     * standing between a stranger's workflow and a coincidence.
     */
    $elsewhere = workspaceWithMember(User::factory()->create(), SystemRole::Admin);
    Feature::for($elsewhere)->activate(Contracts::class);
    Feature::for($elsewhere)->activate(WorkflowsFeature::class);

    $theirs = Contract::factory()->sent()->create(['workspace_id' => $elsewhere->id]);
    $theirSigner = ContractSigner::factory()->for($theirs)->create();

    $run = awaitingRun($workflow, $theirs, $channel);

    expect($run->status)->toBe(WorkflowRunStatus::Waiting);

    ContractSigned::dispatch($theirs->id, $theirSigner->id);

    expect($run->fresh()->status)->toBe(WorkflowRunStatus::Waiting);
});

it('refuses to wait about a record nobody named', function () {
    [$workflow] = awaitScene();

    // Turning this into a plain delay would be wrong in a way nobody would
    // catch reading the workflow back.
    $run = runStep($workflow, 'wait-for-event', [
        'event' => 'contract-signed',
        'minutes' => 60,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('contract');
});

it('refuses a wait with no deadline worth the name', function () {
    [$workflow, $contract] = awaitScene();

    $run = runStep($workflow, 'wait-for-event', [
        'event' => 'contract-signed',
        'minutes' => 0,
        'record_id' => $contract->id,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('minstens een minuut');
});

it('waits about a record a variable names, not only the trigger\'s own', function () {
    [$workflow, $contract, , $channel] = awaitScene();

    $other = Contract::factory()->sent()->create([
        'workspace_id' => $contract->workspace_id,
        'created_by' => $contract->created_by,
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('wait-for-event', [
        'event' => 'contract-signed',
        'minutes' => 60,
        'record_id' => '{{ steps.9.contract.id }}',
    ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => [
            'depth' => 1,
            'trigger' => ['contract' => ['id' => $contract->id]],
            'steps' => ['9' => ['contract' => ['id' => $other->id]]],
        ],
    ]);

    app(RunWorkflow::class)->handle($run);

    // "Wacht tot het contract dat stap negen verstuurde getekend is" names
    // something that did not exist when the run started.
    expect(data_get($run->fresh()->awaiting, 'record'))->toBe($other->id);
});
