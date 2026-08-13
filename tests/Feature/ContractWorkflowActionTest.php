<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Features\Contracts;
use App\Features\Workflows as WorkflowsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * A workspace that asks for signatures, a workflow of its beheerder's, and a
 * contract that is out to one stranger.
 *
 * @return array{0: User, 1: Workspace, 2: Workflow, 3: Contract, 4: ContractSigner}
 */
function contractActionScene(): array
{
    Storage::fake('local');
    Mail::fake();

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Contracts::class);

    channelWithMember($workspace, $author);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Offerte dakwerk',
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $signer = ContractSigner::factory()->for($contract)->create(['name' => 'Jan de Vries']);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'name' => 'Contractmelder',
    ]);

    return [$author, $workspace, $workflow, $contract->refresh(), $signer];
}

/** A template that is genuinely ready to be sent: document, boxes, one party. */
function sendableTemplate(Workspace $workspace, User $author): Contract
{
    $template = Contract::factory()->template(1)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst',
    ]);

    $template->addMedia(UploadedFile::fake()->create('template.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    ContractField::factory()->for($template, 'contract')->create(['signer_index' => 0]);

    return $template->refresh();
}

it('reminds whoever has not answered the contract the trigger was about', function () {
    [, , $workflow, $contract] = contractActionScene();

    $run = runStep($workflow, 'remind-contract-signers', [], [
        'trigger' => ['contract' => ['id' => $contract->id]],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        // One outstanding signer, so one nudge — and the number is what a
        // following step reads to decide whether to say anything.
        ->and(data_get($run->context, 'steps.0.reminded'))->toBe(1)
        ->and(data_get($run->context, 'steps.0.contract.title'))->toBe('Offerte dakwerk');
});

it('withdraws a contract, and says so plainly when there is nothing left to withdraw', function () {
    [, , $workflow, $contract] = contractActionScene();

    $run = runStep($workflow, 'cancel-contract', ['contract_id' => $contract->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($contract->fresh()->status)->toBe(ContractStatus::Cancelled)
        ->and(data_get($run->context, 'steps.0.contract.status'))->toBe('cancelled');

    // And again, on a contract that is already stopped.
    $second = runStep($workflow, 'cancel-contract', ['contract_id' => $contract->id]);

    expect($second->status)->toBe(WorkflowRunStatus::Failed)
        ->and($second->failure_reason)->not->toBeEmpty();
});

it('puts the contract card in a channel', function () {
    [$author, $workspace, $workflow, $contract] = contractActionScene();

    $channel = $workspace->channels()->first();

    $run = runStep($workflow, 'post-contract-to-channel', [
        'contract_id' => $contract->id,
        'channel_id' => $channel->id,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(Message::query()->where('channel_id', $channel->id)->count())->toBe(1)
        ->and(data_get($run->context, 'steps.0.message.id'))->not->toBeNull();
});

/**
 * Nought is an ordinary answer here: a contract whose signed copy was never
 * composed has nothing to attach, and a following step is better served by the
 * number than by a failed run.
 */
it('reports nought copies when there is no signed document to send', function () {
    [, , $workflow, $contract] = contractActionScene();

    $run = runStep($workflow, 'send-signed-contract', ['contract_id' => $contract->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.sent'))->toBe(0);
});

it('queues the render again, but only for a contract that is finished', function () {
    Queue::fake();

    [, , $workflow, $contract] = contractActionScene();

    $tooEarly = runStep($workflow, 'retry-contract-render', ['contract_id' => $contract->id]);

    expect($tooEarly->status)->toBe(WorkflowRunStatus::Failed);

    Queue::assertNothingPushed();

    $contract->forceFill(['status' => ContractStatus::Completed, 'completed_at' => now()])->save();

    $run = runStep($workflow, 'retry-contract-render', ['contract_id' => $contract->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded);

    Queue::assertPushed(RenderSignedContractJob::class);
});

/**
 * The action the whole slice was written for: a form comes in, and the contract
 * goes out to the address that was typed into it.
 */
it('sends a contract out of a template, to whoever the trigger named', function () {
    [$author, $workspace, $workflow] = contractActionScene();

    $template = sendableTemplate($workspace, $author);

    $run = runStep($workflow, 'send-contract-from-template', [
        'template_id' => $template->id,
        'signer_name' => '{{ trigger.answers.naam }}',
        'signer_email' => '{{ trigger.answers.email }}',
        'title' => 'Huurovereenkomst {{ trigger.answers.naam }}',
        'valid_for_days' => 14,
    ], [
        'trigger' => ['answers' => ['naam' => 'Jan de Vries', 'email' => 'jan@klant.nl']],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded);

    $sent = Contract::query()
        ->where('workspace_id', $workspace->id)
        ->where('is_template', false)
        ->where('title', 'Huurovereenkomst Jan de Vries')
        ->first();

    expect($sent)->not->toBeNull()
        ->and($sent->status)->toBe(ContractStatus::Sent)
        ->and($sent->expires_at)->not->toBeNull()
        ->and($sent->signers->pluck('email')->all())->toContain('jan@klant.nl')
        // The template is left exactly as it was: it is a mould, and sending
        // one would put the only copy of it in front of a stranger.
        ->and($template->fresh()->status)->toBe(ContractStatus::Draft)
        ->and(data_get($run->context, 'steps.0.signer.email'))->toBe('jan@klant.nl');
});

it('refuses an address that did not survive the variables', function () {
    [$author, $workspace, $workflow] = contractActionScene();

    $template = sendableTemplate($workspace, $author);

    $run = runStep($workflow, 'send-contract-from-template', [
        'template_id' => $template->id,
        'signer_name' => 'Jan',
        'signer_email' => '{{ trigger.answers.email }}',
    ], ['trigger' => ['answers' => []]]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('mailadres');

    // Nothing was copied on the way to that refusal.
    expect(Contract::query()->where('is_template', false)->where('workspace_id', $workspace->id)->count())
        ->toBe(1);
});

it('says so when a template is not finished being prepared', function () {
    [$author, $workspace, $workflow] = contractActionScene();

    // No boxes drawn on it, which is one of the four things isReadyToSend asks.
    $bare = Contract::factory()->template(1)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Half sjabloon',
    ]);

    $run = runStep($workflow, 'send-contract-from-template', [
        'template_id' => $bare->id,
        'signer_name' => 'Jan',
        'signer_email' => 'jan@klant.nl',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('Half sjabloon');
});

it('cannot reach a contract from another workspace', function () {
    [, , $workflow] = contractActionScene();

    $elsewhere = Workspace::factory()->create();
    $theirs = Contract::factory()->sent()->create(['workspace_id' => $elsewhere->id]);

    $run = runStep($workflow, 'cancel-contract', ['contract_id' => $theirs->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($theirs->fresh()->status)->toBe(ContractStatus::Sent);
});

it('stops when the workspace has switched contracts off', function () {
    [, $workspace, $workflow, $contract] = contractActionScene();

    Feature::for($workspace)->deactivate(Contracts::class);

    $run = runStep($workflow, 'remind-contract-signers', ['contract_id' => $contract->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->not->toBeEmpty();
});

/*
 * A draft being built up out of what happens: the contract exists, and the
 * second tenant is named by the form that came in afterwards.
 */
it('puts another signer on a draft, and keeps the one already there', function () {
    [$author, $workspace, $workflow] = contractActionScene();

    $draft = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst',
        'status' => ContractStatus::Draft,
    ]);

    $first = ContractSigner::factory()->for($draft)->inPosition(0)->create([
        'name' => 'Jan de Vries',
        'email' => 'jan@klant.nl',
    ]);

    $run = runStep($workflow, 'add-contract-signer', [
        'contract_id' => $draft->id,
        'signer_name' => '{{ trigger.answers.naam }}',
        'signer_email' => '{{ trigger.answers.email }}',
    ], [
        'trigger' => ['answers' => ['naam' => 'Marieke de Vries', 'email' => 'marieke@klant.nl']],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($draft->signers()->orderBy('signing_order')->pluck('email')->all())
        ->toBe(['jan@klant.nl', 'marieke@klant.nl'])
        // The first signer keeps the link they were given: adding a name must
        // not rotate a token somebody is holding.
        ->and($first->fresh()->token)->toBe($first->token)
        ->and(data_get($run->context, 'steps.0.signer.email'))->toBe('marieke@klant.nl');
});

it('adds nobody to a contract that has already gone out', function () {
    [, , $workflow, $contract] = contractActionScene();

    $run = runStep($workflow, 'add-contract-signer', [
        'contract_id' => $contract->id,
        'signer_name' => 'Marieke',
        'signer_email' => 'marieke@klant.nl',
    ]);

    /*
     * The boxes on a sent contract point at the people who were on it when it
     * left, so a name appended now is a signature line nobody drew.
     */
    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('al verstuurd')
        ->and($contract->signers()->count())->toBe(1);
});

it('refuses to add somebody who is already on the contract', function () {
    [$author, $workspace, $workflow] = contractActionScene();

    $draft = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'status' => ContractStatus::Draft,
    ]);

    ContractSigner::factory()->for($draft)->inPosition(0)->create(['email' => 'jan@klant.nl']);

    $run = runStep($workflow, 'add-contract-signer', [
        'contract_id' => $draft->id,
        'signer_name' => 'Jan',
        // The same address in different capitals is the same person, which is
        // the rule everywhere else this application matches signers.
        'signer_email' => 'JAN@klant.nl',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('staat al')
        ->and($draft->signers()->count())->toBe(1);
});

/*
 * The monthly lease: the same document, for somebody new, with none of what
 * happened to the original.
 */
it('duplicates a contract into a fresh draft with nobody on it', function () {
    [, $workspace, $workflow, $contract, $signer] = contractActionScene();

    $signer->forceFill(['signed_at' => now()])->save();
    $contract->forceFill(['status' => ContractStatus::Completed, 'completed_at' => now()])->save();

    $run = runStep($workflow, 'duplicate-contract', [
        'contract_id' => $contract->id,
        'title' => 'Offerte dakwerk {{ trigger.answers.naam }}',
    ], [
        'trigger' => ['answers' => ['naam' => 'Bakker']],
    ]);

    $copy = Contract::query()
        ->where('workspace_id', $workspace->id)
        ->where('title', 'Offerte dakwerk Bakker')
        ->first();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($copy)->not->toBeNull()
        ->and($copy->status)->toBe(ContractStatus::Draft)
        // Nobody on it, and nothing of what the original went through: a
        // signature carried across would be claiming somebody signed a document
        // they have never seen.
        ->and($copy->signers()->count())->toBe(0)
        ->and($copy->completed_at)->toBeNull()
        // And the original is left exactly as it was, which is the point of
        // copying a completed contract rather than editing it.
        ->and($contract->fresh()->status)->toBe(ContractStatus::Completed)
        ->and(data_get($run->context, 'steps.0.contract.id'))->toBe($copy->id);
});

it('refuses to duplicate a contract into a title that came up empty', function () {
    [, , $workflow, $contract] = contractActionScene();

    $run = runStep($workflow, 'duplicate-contract', [
        'contract_id' => $contract->id,
        'title' => '{{ trigger.answers.naam }}',
    ]);

    // A copy named after a variable that held nothing would sit in the list
    // beside the original with nothing to tell them apart.
    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('titel');
});
