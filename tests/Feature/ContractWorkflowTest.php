<?php

use App\Actions\Contracts\CancelContract;
use App\Actions\Contracts\PruneContracts;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Events\ContractCompleted;
use App\Events\ContractDeclined;
use App\Events\ContractExpired;
use App\Events\ContractSent;
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
use App\Models\Workspace;
use App\Workflows\Triggers\ContractCancelledTrigger;
use App\Workflows\Triggers\ContractCompletedTrigger;
use App\Workflows\Triggers\ContractDeclinedTrigger;
use App\Workflows\Triggers\ContractExpiredTrigger;
use App\Workflows\Triggers\ContractSentTrigger;
use App\Workflows\Triggers\ContractSignedTrigger;
use App\Workflows\Triggers\ContractTrigger;
use Laravel\Pennant\Feature;

/**
 * A workspace that asks for signatures and runs workflows, with a contract out
 * to two people: one colleague and one stranger.
 *
 * The stranger is part of the fixture rather than an extra step, the way the
 * ticket fixture keeps a guest: what the interesting conditions are about is
 * the line between somebody from the house and somebody from outside, and a
 * contract with only colleagues on it cannot show where that line runs.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Contract, 4: ContractSigner, 5: ContractSigner}
 */
function contractWorkflowScene(): array
{
    $author = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Contracts::class);

    $channel = channelWithMember($workspace, $author);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Offerte dakwerk',
        'status' => ContractStatus::Sent,
        'notify_channel_id' => $channel->id,
        'expires_at' => now()->addDays(2),
    ]);

    $colleague = ContractSigner::factory()->for($contract)->forUser($author)->inPosition(0)->create();
    $stranger = ContractSigner::factory()->for($contract)->inPosition(1)->create(['name' => 'Jan de Vries']);

    return [$author, $workspace, $channel, $contract, $colleague, $stranger];
}

/** A switched-on workflow waiting for one contract moment, with one harmless step. */
function contractWorkflow(User $author, Workspace $workspace, string $trigger, array $config = []): Workflow
{
    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'name' => 'Contractmelder',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $workspace->channels()->value('id'),
    ])->create();

    return $workflow;
}

function contractRunOf(Workflow $workflow): ?WorkflowRun
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->first();
}

it('is called what a workflow stores, for every one of the eight', function () {
    expect(ContractSentTrigger::key())->toBe('contract-sent')
        ->and(ContractSignedTrigger::key())->toBe('contract-signed')
        ->and(ContractDeclinedTrigger::key())->toBe('contract-declined')
        ->and(ContractCompletedTrigger::key())->toBe('contract-completed')
        ->and(ContractCancelledTrigger::key())->toBe('contract-cancelled')
        ->and(ContractExpiredTrigger::key())->toBe('contract-expired');
});

it('hands a workflow everything it knows about the contract', function () {
    [$author, $workspace, $channel, $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractSentTrigger::key());

    ContractSent::dispatch($contract->id);

    $run = contractRunOf($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.contract.title'))->toBe('Offerte dakwerk')
        ->and(data_get($run->context, 'trigger.contract.status'))->toBe('sent')
        ->and(data_get($run->context, 'trigger.contract.signer_count'))->toBe(2)
        ->and(data_get($run->context, 'trigger.contract.signed_count'))->toBe(0)
        ->and(data_get($run->context, 'trigger.contract.remaining'))->toBe(2)
        // The number that makes "verloopt binnen drie dagen" expressible at
        // all: a condition can compare a number but cannot produce one.
        ->and(data_get($run->context, 'trigger.contract.days_until_expiry'))->toBe(2)
        ->and(data_get($run->context, 'trigger.author.name'))->toBe('Sanne')
        ->and(data_get($run->context, 'trigger.channel.id'))->toBe($channel->id)
        // Nobody in particular sent it, so there is no signer half at all —
        // rather than one full of nulls that a message would render as gaps.
        ->and(data_get($run->context, 'trigger.signer'))->toBeNull();
});

it('counts what is left, and tells a stranger from a colleague', function () {
    [$author, $workspace, , $contract, $colleague, $stranger] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractSignedTrigger::key());

    $colleague->forceFill(['signed_at' => now()])->save();

    ContractSigned::dispatch($contract->id, $colleague->id);

    $run = contractRunOf($workflow);

    expect(data_get($run->context, 'trigger.signer.name'))->toBe('Sanne')
        ->and(data_get($run->context, 'trigger.signer.is_external'))->toBeFalse()
        ->and(data_get($run->context, 'trigger.signer.is_last'))->toBeFalse()
        ->and(data_get($run->context, 'trigger.contract.signed_count'))->toBe(1)
        ->and(data_get($run->context, 'trigger.contract.remaining'))->toBe(1);

    // And the second answer closes it: is_last says so without anybody having
    // to work out the arithmetic in a condition.
    $stranger->forceFill(['signed_at' => now()])->save();

    ContractSigned::dispatch($contract->id, $stranger->id);

    $second = WorkflowRun::query()->where('workflow_id', $workflow->id)->latest('id')->first();

    expect(data_get($second->context, 'trigger.signer.is_external'))->toBeTrue()
        ->and(data_get($second->context, 'trigger.signer.is_last'))->toBeTrue()
        ->and(data_get($second->context, 'trigger.contract.remaining'))->toBe(0);
});

it('carries the reason somebody gave for refusing', function () {
    [$author, $workspace, , $contract, , $stranger] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractDeclinedTrigger::key());

    $stranger->forceFill(['declined_at' => now(), 'decline_reason' => 'Prijs klopt niet'])->save();

    ContractDeclined::dispatch($contract->id, $stranger->id);

    expect(data_get(contractRunOf($workflow)->context, 'trigger.signer.decline_reason'))
        ->toBe('Prijs klopt niet');
});

/**
 * The link only exists once the render has run, which is why the completed
 * trigger waits for the job rather than for the status.
 */
it('offers a download link on a finished contract and nowhere else', function () {
    [$author, $workspace, , $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractCompletedTrigger::key());

    $contract->forceFill(['status' => ContractStatus::Completed, 'completed_at' => now()])->save();

    ContractCompleted::dispatch($contract->id);

    expect(data_get(contractRunOf($workflow)->context, 'trigger.contract.download_url'))
        ->toContain((string) $contract->id);
});

it('starts a workflow when a contract is actually stopped', function () {
    [$author, $workspace, , $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractCancelledTrigger::key());

    app(CancelContract::class)->handle($contract);

    expect(contractRunOf($workflow))->not->toBeNull()
        ->and(data_get(contractRunOf($workflow)->context, 'trigger.contract.status'))->toBe('cancelled');
});

/**
 * The prune used to close these in one UPDATE, which fires nothing. A trigger
 * hung off an event nobody sends is a workflow that quietly never runs.
 */
it('announces every contract the nightly prune closes', function () {
    [$author, $workspace, , $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractExpiredTrigger::key());

    $contract->forceFill(['expires_at' => now()->subDay()])->save();

    expect(app(PruneContracts::class)->handle()['expired'])->toBe(1)
        ->and(contractRunOf($workflow))->not->toBeNull()
        ->and($contract->fresh()->status)->toBe(ContractStatus::Expired);
});

it('only starts the workflows written about this sort of contract', function () {
    [$author, $workspace, $channel, $contract] = contractWorkflowScene();

    $elsewhere = Channel::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $author->id]);

    $wrongChannel = contractWorkflow($author, $workspace, ContractSentTrigger::key(), ['channel_id' => $elsewhere->id]);
    $rightChannel = contractWorkflow($author, $workspace, ContractSentTrigger::key(), ['channel_id' => $channel->id]);
    $wrongWords = contractWorkflow($author, $workspace, ContractSentTrigger::key(), ['title_words' => ['geheimhouding']]);
    $rightWords = contractWorkflow($author, $workspace, ContractSentTrigger::key(), ['title_words' => ['offerte', 'prijsopgave']]);
    $everything = contractWorkflow($author, $workspace, ContractSentTrigger::key());

    ContractSent::dispatch($contract->id);

    expect(contractRunOf($wrongChannel))->toBeNull()
        ->and(contractRunOf($rightChannel))->not->toBeNull()
        ->and(contractRunOf($wrongWords))->toBeNull()
        ->and(contractRunOf($rightWords))->not->toBeNull()
        // Nothing filled in means everything, which is what makes a workflow
        // written in five seconds do something.
        ->and(contractRunOf($everything))->not->toBeNull();
});

it('stays out of a workspace that has switched contracts off', function () {
    [$author, $workspace, , $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractSentTrigger::key());

    Feature::for($workspace)->deactivate(Contracts::class);

    ContractSent::dispatch($contract->id);

    expect(contractRunOf($workflow))->toBeNull()
        ->and(ContractSentTrigger::availableFor($workspace->fresh()))->toBeFalse();
});

it('says nothing about a contract that has since been deleted', function () {
    [$author, $workspace, , $contract] = contractWorkflowScene();
    $workflow = contractWorkflow($author, $workspace, ContractExpiredTrigger::key());

    $id = $contract->id;
    $contract->delete();

    ContractExpired::dispatch($id);

    expect(contractRunOf($workflow))->toBeNull();
});

it('promises the same three filters on every one of them', function () {
    $fields = array_map(fn ($field): string => $field->key, ContractSentTrigger::fields());

    expect($fields)->toBe(['channel_id', 'author_id', 'title_words'])
        ->and(ContractSentTrigger::provides())->toHaveKeys([
            'contract.id', 'contract.days_until_expiry', 'contract.remaining', 'author.name',
        ])
        // The signer half is only promised where there is one, so the variable
        // picker never offers a path that renders as nothing.
        ->and(ContractSentTrigger::provides())->not->toHaveKey('signer.name')
        ->and(ContractSignedTrigger::provides())->toHaveKey('signer.is_external')
        ->and(ContractDeclinedTrigger::provides())->toHaveKey('signer.decline_reason')
        ->and(ContractCompletedTrigger::provides())->toHaveKey('contract.download_url')
        ->and(is_subclass_of(ContractSentTrigger::class, ContractTrigger::class))->toBeTrue();
});
