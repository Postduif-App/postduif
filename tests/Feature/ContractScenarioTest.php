<?php

use App\Actions\Contracts\PruneContracts;
use App\Actions\Workflows\ResumeWaitingWorkflows;
use App\Enums\ChannelTicketPolicy;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkflowBranch;
use App\Enums\WorkflowRunStatus;
use App\Events\ContractCompleted;
use App\Events\ContractDeclined;
use App\Events\ContractRenderFailed;
use App\Events\ContractSent;
use App\Events\ContractSigned;
use App\Features\Contracts;
use App\Features\Tickets;
use App\Features\Workflows as WorkflowsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/*
 * The six workflows a workspace that sends contracts actually wants, each built
 * whole — trigger, steps, conditions — and set off by the real thing.
 *
 * The point of this file is not coverage. The other contract tests prove that
 * each piece works; these prove the pieces add up to something somebody would
 * write, and they are where it shows if they do not. Three things turned out
 * not to be expressible, and each of them is written down beside the scenario
 * it spoiled rather than quietly designed around.
 */

/**
 * A workspace that sends contracts, keeps tickets and runs workflows, with a
 * contract out to one colleague and one stranger.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Contract, 4: ContractSigner, 5: ContractSigner}
 */
function contractScenarioScene(): array
{
    Storage::fake('local');
    Mail::fake();

    $author = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Contracts::class);
    Feature::for($workspace)->activate(Tickets::class);

    $channel = channelWithMember($workspace, $author);

    // The board has to be open in the channel, or the ticket scenario would
    // fail for a reason that has nothing to do with contracts.
    $channel->forceFill(['ticket_policy' => ChannelTicketPolicy::Everyone])->save();

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Offerte dakwerk',
        'notify_channel_id' => $channel->id,
        'expires_at' => now()->addDays(2),
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $colleague = ContractSigner::factory()->for($contract)->forUser($author)->inPosition(0)->create();
    $stranger = ContractSigner::factory()->for($contract)->inPosition(1)->create(['name' => 'Jan de Vries']);

    return [$author, $workspace, $channel, $contract->refresh(), $colleague, $stranger];
}

/** An empty, switched-on workflow waiting for one contract moment. */
function scenarioWorkflow(User $author, Workspace $workspace, string $trigger): Workflow
{
    return Workflow::factory()->enabled()->triggeredBy($trigger, [])->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'name' => 'Contractdienst',
    ]);
}

/** What a channel was told, in the order it was told. */
function saidIn(Channel $channel): array
{
    return Message::query()
        ->where('channel_id', $channel->id)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

/**
 * Scenario 1: sent, and three days later still not signed → nudge whoever is
 * quiet, and say something in the channel when the deadline is close.
 *
 * The one every workspace that sends contracts ends up wanting, and the one
 * that shows the sharpest limitation in the builder as it stands. See the note
 * on the condition below: after a delay, a workflow is still reading what the
 * trigger saw three days ago.
 */
it('nudges the quiet ones three days after sending, and warns when the deadline is close', function () {
    [$author, $workspace, $channel, $contract] = contractScenarioScene();

    /*
     * A fortnight to answer, which is what makes this scenario a scenario: a
     * contract whose deadline passes while the workflow is waiting can no
     * longer be reminded at all — ContractPolicy::remind says so — and the step
     * fails rather than nudging somebody about something that has closed.
     */
    $contract->forceFill(['expires_at' => now()->addDays(14)])->save();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-sent');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('delay', ['minutes' => 3 * 24 * 60])->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('remind-contract-signers', [])->create();

    /*
     * The condition reads trigger.contract.days_until_expiry, which was worked
     * out when the contract was sent and not when this step got its turn — the
     * run's context is a snapshot. Here that is harmless and even right: the
     * workflow is asking "was this a short-dated contract", which is a fact
     * about the sending. What cannot be asked is "how does it stand now" —
     * see pcom-ybal.20, which is that finding written up.
     */
    WorkflowStep::factory()->for($workflow)->at(2)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => '{{ trigger.contract.title }} verloopt bijna en is nog niet rond.',
        ])
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [[
                'path' => 'trigger.contract.days_until_expiry',
                'operator' => 'less-or-equal',
                'value' => '14',
            ]],
        ])
        ->create();

    ContractSent::dispatch($contract->id);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->firstOrFail();

    // Waiting rather than done: the delay is the first step, and the run sits
    // there until the sweep picks it up.
    expect($run->status)->toBe(WorkflowRunStatus::Waiting)
        ->and(saidIn($channel))->toBeEmpty();

    $this->travel(4)->days();

    expect(app(ResumeWaitingWorkflows::class)->handle())->toBe(1);

    $run->refresh();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        // Both were nudged: neither has answered.
        ->and(data_get($run->context, 'steps.1.reminded'))->toBe(2)
        ->and(saidIn($channel))->toBe(['Offerte dakwerk verloopt bijna en is nog niet rond.']);

    $this->travelBack();
});

/**
 * Scenario 2: somebody refuses → say so where the contract is followed, with
 * the reason they gave.
 *
 * The finding here is what is *not* in this workflow. The story asked for a
 * message to the author as well, and that cannot be written: SendDirectMessage
 * picks a person from a list, and a person picker takes no variable — so
 * "de aanvrager van dit contract" has no way of being said — see pcom-ybal.19.
 * The channel gets the news; the author only hears about it because the
 * contract feature mails them itself.
 */
it('tells the channel when somebody refuses, and why', function () {
    [$author, $workspace, $channel, $contract, , $stranger] = contractScenarioScene();

    $stranger->forceFill(['declined_at' => now(), 'decline_reason' => 'Prijs klopt niet'])->save();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-declined');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-channel-message', [
            'channel_id' => '{{ trigger.channel.id }}',
            'body' => '{{ trigger.signer.name }} tekent {{ trigger.contract.title }} niet: {{ trigger.signer.decline_reason }}',
        ])->create();

    ContractDeclined::dispatch($contract->id, $stranger->id);

    expect(saidIn($channel))->toBe([
        'Jan de Vries tekent Offerte dakwerk niet: Prijs klopt niet',
    ]);
});

/**
 * Scenario 3: finished → everybody gets their copy, and the administration
 * gets a ticket about it.
 *
 * The copy goes out by itself when a contract completes; the step is here
 * because a workflow that has to be sure is better off saying so than assuming
 * — and it costs nothing when there is nobody left to send to.
 */
it('hands out the signed copy and opens a ticket for the administration', function () {
    [$author, $workspace, $channel, $contract, $colleague, $stranger] = contractScenarioScene();

    $colleague->forceFill(['signed_at' => now()])->save();
    $stranger->forceFill(['signed_at' => now()])->save();
    $contract->forceFill(['status' => ContractStatus::Completed, 'completed_at' => now()])->save();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-completed');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-signed-contract', ['again' => 'no'])->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('create-ticket', [
            'channel_id' => $channel->id,
            'title' => 'Contract getekend: {{ trigger.contract.title }}',
            'body' => 'Getekend door {{ trigger.contract.signers }}. {{ trigger.contract.download_url }}',
        ])->create();

    ContractCompleted::dispatch($contract->id);

    $ticket = Ticket::query()->where('workspace_id', $workspace->id)->first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->title)->toBe('Contract getekend: Offerte dakwerk')
        ->and($ticket->body)->toContain('Jan de Vries')
        // The link the completed trigger waits for the render to produce.
        ->and($ticket->body)->toContain('/ondertekend');
});

/**
 * Scenario 4: a customer signing is not a colleague signing.
 *
 * The fork the whole is_external path was put in for — one workflow, two
 * sentences, decided by whether there is an account behind the signature.
 */
it('says a different thing for a customer than for a colleague', function () {
    [$author, $workspace, $channel, $contract, $colleague, $stranger] = contractScenarioScene();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-signed');

    $fork = WorkflowStep::factory()->for($workflow)->at(0)->forking()
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [['path' => 'trigger.signer.is_external', 'operator' => 'is-true', 'value' => '']],
        ])
        ->create();

    WorkflowStep::factory()->for($workflow)->at(1)->inLane($fork, WorkflowBranch::Then)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => 'De klant ({{ trigger.signer.name }}) heeft getekend.',
        ])->create();

    WorkflowStep::factory()->for($workflow)->at(2)->inLane($fork, WorkflowBranch::Else)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => 'Collega {{ trigger.signer.name }} heeft meegetekend.',
        ])->create();

    $colleague->forceFill(['signed_at' => now()])->save();
    ContractSigned::dispatch($contract->id, $colleague->id);

    $stranger->forceFill(['signed_at' => now()])->save();
    ContractSigned::dispatch($contract->id, $stranger->id);

    expect(saidIn($channel))->toBe([
        'Collega Sanne heeft meegetekend.',
        'De klant (Jan de Vries) heeft getekend.',
    ]);
});

/**
 * Scenario 5: the signed copy could not be made → tell whoever keeps the place
 * running, and try again in the same breath.
 *
 * The retry is the interesting half. The ordinary failure here is a machine
 * that was busy, so putting the second attempt in the workflow means the usual
 * case has fixed itself before anybody reads the message.
 */
it('reports a failed render and queues another attempt', function () {
    // Only this one: Queue::fake() with no argument would swallow
    // RunWorkflowJob as well, and the workflow under test would never run.
    Queue::fake([RenderSignedContractJob::class]);

    [$author, $workspace, $channel, $contract] = contractScenarioScene();

    $contract->forceFill([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
        'render_failed_at' => now(),
    ])->save();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-render-failed');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => 'De getekende PDF van {{ trigger.contract.title }} kon niet gemaakt worden. Nieuwe poging loopt.',
        ])->create();

    WorkflowStep::factory()->for($workflow)->at(1)
        ->doing('retry-contract-render', [])->create();

    ContractRenderFailed::dispatch($contract->id);

    expect(saidIn($channel))->toHaveCount(1)
        // The mark comes off, which is what stops the overview from going on
        // offering "opnieuw proberen" while an attempt is in flight.
        ->and($contract->fresh()->render_failed_at)->toBeNull();

    Queue::assertPushed(RenderSignedContractJob::class);
});

/**
 * Scenario 6: expired → say so, and start a fresh copy.
 *
 * Half of this one cannot be built. There is no action that duplicates a
 * contract, so "en zet er meteen een nieuw concept van klaar" has nowhere to
 * go — see pcom-ybal.18, which is the honest outcome of trying rather than
 * something to design around. What is here is the half that works.
 */
it('announces an expired contract, and stops where the builder runs out', function () {
    [$author, $workspace, $channel, $contract] = contractScenarioScene();

    $contract->forceFill(['expires_at' => now()->subDay()])->save();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-expired');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-channel-message', [
            'channel_id' => '{{ trigger.channel.id }}',
            'body' => '{{ trigger.contract.title }} is verlopen zonder dat iedereen tekende ({{ trigger.contract.signed_count }} van {{ trigger.contract.signer_count }}).',
        ])->create();

    app(PruneContracts::class)->handle();

    expect(saidIn($channel))->toBe([
        'Offerte dakwerk is verlopen zonder dat iedereen tekende (0 van 2).',
    ]);
});

/**
 * And the one every scenario above leans on without saying so: a workflow only
 * ever reaches its own workspace.
 */
it('does not let one workspace his contract workflow see another his contract', function () {
    [$author, $workspace, $channel] = contractScenarioScene();

    $workflow = scenarioWorkflow($author, $workspace, 'contract-sent');

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-channel-message', ['channel_id' => $channel->id, 'body' => 'Verstuurd.'])
        ->create();

    $elsewhere = Workspace::factory()->create();
    Feature::for($elsewhere)->activate(Contracts::class);

    $theirs = Contract::factory()->sent()->create(['workspace_id' => $elsewhere->id]);

    ContractSent::dispatch($theirs->id);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0)
        ->and(saidIn($channel))->toBeEmpty();
});
