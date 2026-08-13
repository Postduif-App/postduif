<?php

use App\Actions\Contracts\DeclineContract;
use App\Actions\Contracts\SignContract;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\DeliverContractWebhookJob;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\ContractWebhook;
use App\Models\User;
use App\Models\Workspace;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

/**
 * Telling somebody else's system what happened to a contract.
 *
 * Two properties carry the whole feature and both are about damage: a delivery
 * that fails must never touch the signature it was reporting, and an address a
 * beheerder typed must never be a way to reach the inside of this network. Most
 * of what is below is one of those two said in a different way.
 *
 * Every address here is written as an IP address rather than a name, because
 * GuardOutboundUrl resolves what it is given and a test that hits DNS is a test
 * that fails on a train — the same reasoning the workflow HTTP suite writes out.
 */

/**
 * A contract that has been sent, with one person left to answer.
 *
 * A real file on disk, because signing measures the document it is signing and
 * a faked one could not tell a changed contract from an unchanged one.
 *
 * @return array{0: Workspace, 1: Contract, 2: ContractSigner}
 */
function subscribedContract(array $state = []): array
{
    Storage::fake('local');

    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'page_count' => 1,
        ...$state,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $contract->update(['source_hash' => hash_file('sha256', $contract->source()->getPath())]);

    $signer = ContractSigner::factory()->create(['contract_id' => $contract->id]);

    return [$workspace, $contract->fresh(), $signer];
}

/** Run one delivery here and now, the way a worker would. */
function deliver(string $event, Contract $contract, ?ContractWebhook $webhook): void
{
    (new DeliverContractWebhookJob(
        $event,
        $contract->id,
        $webhook?->id,
        now()->toIso8601String(),
    ))->handle(app(GuardOutboundUrl::class));
}

it('plans exactly one delivery per subscription that asked for this event', function () {
    Queue::fake();

    [$workspace, , $signer] = subscribedContract();

    $wants = ContractWebhook::factory()->count(2)->create(['workspace_id' => $workspace->id]);

    /*
     * A third subscription in the same workspace that only asked about
     * refusals. The point of the test: "iedereen die geabonneerd is" is not the
     * same list as "iedereen die dit wilde horen".
     */
    ContractWebhook::factory()
        ->forEvents([ContractWebhook::EVENT_DECLINED])
        ->create(['workspace_id' => $workspace->id]);

    // And one belonging to somebody else entirely, which must never hear a word
    // about this workspace's contracts.
    ContractWebhook::factory()->create();

    app(SignContract::class)->handle($signer, '203.0.113.4');

    Queue::assertPushed(DeliverContractWebhookJob::class, 2);

    foreach ($wants as $webhook) {
        Queue::assertPushed(
            DeliverContractWebhookJob::class,
            fn (DeliverContractWebhookJob $job): bool => $job->webhookId === $webhook->id
                && $job->event === ContractWebhook::EVENT_SIGNED,
        );
    }
});

it('plans nothing for a subscription that has been switched off', function () {
    Queue::fake();

    [$workspace, , $signer] = subscribedContract();

    ContractWebhook::factory()->disabled()->create(['workspace_id' => $workspace->id]);

    app(SignContract::class)->handle($signer, '203.0.113.4');

    Queue::assertNotPushed(DeliverContractWebhookJob::class);
});

it('says nothing at all about a template', function () {
    Queue::fake();

    /*
     * A template's author signing it is not an agreement — it is them putting
     * half a document in place for the contracts that will be made from it. A
     * subscriber told "getekend" about this would be told about a document
     * nobody has ever been sent.
     */
    [$workspace, , $signer] = subscribedContract([
        'is_template' => true,
        'status' => ContractStatus::Draft,
        'required_signers' => 1,
    ]);

    ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    app(SignContract::class)->handle($signer, '203.0.113.4');

    expect($signer->fresh()->signed_at)->not->toBeNull();

    Queue::assertNotPushed(DeliverContractWebhookJob::class);
});

it('reports a refusal as its own event', function () {
    Queue::fake();

    [$workspace, , $signer] = subscribedContract();

    $webhook = ContractWebhook::factory()
        ->forEvents([ContractWebhook::EVENT_DECLINED])
        ->create(['workspace_id' => $workspace->id]);

    app(DeclineContract::class)->handle($signer, 'Niet akkoord met artikel 4');

    Queue::assertPushed(
        DeliverContractWebhookJob::class,
        fn (DeliverContractWebhookJob $job): bool => $job->webhookId === $webhook->id
            && $job->event === ContractWebhook::EVENT_DECLINED,
    );
});

it('signs the exact bytes it sends, with the secret on the row', function () {
    Http::fake(['*' => Http::response('', 200)]);

    [$workspace, $contract, $signer] = subscribedContract();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    deliver(ContractWebhook::EVENT_SIGNED, $contract, $webhook);

    Http::assertSent(function (ClientRequest $request) use ($webhook): bool {
        /*
         * Verified the way a receiving system has to verify it: run the hash
         * over the raw body that arrived, and compare. If the payload were
         * encoded a second time on the way out — a different key order, a slash
         * escaped where we did not — this is the assertion that would fail, and
         * it would fail for every receiver in the world at the same time.
         */
        expect($request->header('X-Postduif-Signature')[0])
            ->toBe('sha256='.hash_hmac('sha256', $request->body(), $webhook->secret))
            ->and($request->header('X-Postduif-Event')[0])->toBe(ContractWebhook::EVENT_SIGNED);

        return true;
    });

    // And the row says the delivery happened.
    expect($webhook->fresh()->last_status)->toBe(200)
        ->and($webhook->fresh()->last_delivered_at)->not->toBeNull()
        ->and($webhook->fresh()->last_failed_at)->toBeNull();

    expect($signer->fresh())->not->toBeNull();
});

it('describes the contract and everybody who was asked', function () {
    Http::fake(['*' => Http::response('', 200)]);

    [$workspace, $contract, $signer] = subscribedContract();

    $signer->forceFill(['signed_at' => now()])->save();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    deliver(ContractWebhook::EVENT_SIGNED, $contract->fresh(), $webhook);

    Http::assertSent(function (ClientRequest $request) use ($contract, $signer): bool {
        $payload = json_decode($request->body(), associative: true);

        expect($payload['event'])->toBe(ContractWebhook::EVENT_SIGNED)
            ->and($payload['occurredAt'])->not->toBeNull()
            ->and($payload['contract']['id'])->toBe($contract->id)
            ->and($payload['contract']['title'])->toBe($contract->title)
            ->and($payload['contract']['status'])->toBe($contract->status->value)
            ->and($payload['signers'])->toHaveCount(1)
            ->and($payload['signers'][0]['email'])->toBe($signer->email)
            ->and($payload['signers'][0]['signedAt'])->not->toBeNull()
            // No document while the contract is still going round: a link that
            // 404s reads as a broken document rather than an unfinished one.
            ->and($payload['documentUrl'])->toBeNull();

        return true;
    });
});

it('hands over a link to the signed copy once there is one', function () {
    Http::fake(['*' => Http::response('', 200)]);

    [$workspace, $contract] = subscribedContract();

    $contract->update(['status' => ContractStatus::Completed, 'completed_at' => now()]);
    $contract->addMedia(UploadedFile::fake()->create('signed.pdf', 20))
        ->toMediaCollection(Contract::SIGNED);

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    deliver(ContractWebhook::EVENT_COMPLETED, $contract->fresh(), $webhook);

    $link = null;

    Http::assertSent(function (ClientRequest $request) use (&$link): bool {
        $link = json_decode($request->body(), associative: true)['documentUrl'];

        return true;
    });

    expect($link)->not->toBeNull();

    /*
     * And it works without a session, which is the whole reason it is a signed
     * URL rather than the address the chat downloads from: the thing fetching
     * it is a server with no account here.
     */
    get($link)->assertOk()->assertHeader('content-type', 'application/pdf');

    // Tampered with, and it is nothing at all.
    get($link.'x')->assertForbidden();
});

it('marks the row when the far end says no, and leaves the contract alone', function () {
    Http::fake(['*' => Http::response('boem', 500)]);

    [$workspace, $contract, $signer] = subscribedContract();

    $signer->forceFill(['signed_at' => now()])->save();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    /*
     * Thrown rather than swallowed, because that is what puts the delivery back
     * on the queue for its next attempt. What must not happen is anything at
     * all to the contract.
     */
    expect(fn () => deliver(ContractWebhook::EVENT_SIGNED, $contract, $webhook))
        ->toThrow(RuntimeException::class);

    expect($webhook->fresh()->last_status)->toBe(500)
        ->and($webhook->fresh()->last_failed_at)->not->toBeNull()
        ->and($webhook->fresh()->last_delivered_at)->toBeNull();

    expect($contract->fresh()->status)->toBe($contract->status)
        ->and($signer->fresh()->signed_at)->not->toBeNull();
});

it('will not go to an address on the inside of the network', function () {
    Http::preventStrayRequests();
    Http::fake();

    [$workspace, $contract] = subscribedContract();

    /*
     * The cloud metadata endpoint, which is the address this whole guard
     * exists for: it hands out credentials to whoever asks from the machine.
     * Refused before a client is ever handed it, so nothing leaves at all.
     */
    $webhook = ContractWebhook::factory()->create([
        'workspace_id' => $workspace->id,
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ]);

    deliver(ContractWebhook::EVENT_SIGNED, $contract, $webhook);

    Http::assertNothingSent();

    expect($webhook->fresh()->last_failed_at)->not->toBeNull()
        // Nothing answered, so there is no status to have.
        ->and($webhook->fresh()->last_status)->toBeNull();
});

it('also tells the address the contract itself was sent with', function () {
    Queue::fake();

    [, , $signer] = subscribedContract([
        'callback_url' => 'https://93.184.216.34/api/contracten',
        'callback_secret' => 'whs_hetgeheimvandeafzender',
    ]);

    app(SignContract::class)->handle($signer, '203.0.113.4');

    /*
     * No subscription anywhere, and still a delivery: this is the arrangement
     * an API caller makes for the one contract it sent, and it stands entirely
     * apart from what a beheerder set up on the settings screen.
     */
    Queue::assertPushed(
        DeliverContractWebhookJob::class,
        fn (DeliverContractWebhookJob $job): bool => $job->webhookId === null,
    );
});

it('signs a per-contract delivery with the secret that came with the contract', function () {
    Http::fake(['*' => Http::response('', 200)]);

    [, $contract] = subscribedContract([
        'callback_url' => 'https://93.184.216.34/api/contracten',
        'callback_secret' => 'whs_hetgeheimvandeafzender',
    ]);

    deliver(ContractWebhook::EVENT_SIGNED, $contract, null);

    Http::assertSent(fn (ClientRequest $request): bool => $request->header('X-Postduif-Signature')[0]
        === 'sha256='.hash_hmac('sha256', $request->body(), 'whs_hetgeheimvandeafzender'));
});

it('stays silent when a subscription was switched off while the delivery waited', function () {
    Http::preventStrayRequests();
    Http::fake();

    [$workspace, $contract] = subscribedContract();

    $webhook = ContractWebhook::factory()->disabled()->create(['workspace_id' => $workspace->id]);

    deliver(ContractWebhook::EVENT_SIGNED, $contract, $webhook);

    // "Uit" has to mean uit, rather than "uit, over een paar minuten".
    Http::assertNothingSent();
});

/*
 * The screen. Everything below is about who may open it and what they may do
 * with it, which for a list of addresses this server will go and fetch is the
 * same question twice.
 */

/**
 * A beheerder of a workspace that has contracts switched on.
 *
 * @return array{0: User, 1: Workspace}
 */
function webhookBeheerder(SystemRole $role = SystemRole::Admin): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(ContractsFeature::class);

    return [$user, $workspace];
}

it('lets a beheerder subscribe an address', function () {
    [$user, $workspace] = webhookBeheerder();

    actingAs($user)
        ->post(route('workspace.contract-webhooks.store'), [
            'name' => 'Boekhouding',
            'url' => 'https://93.184.216.34/webhooks/contracten',
            'events' => [ContractWebhook::EVENT_COMPLETED, ContractWebhook::EVENT_SIGNED],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $webhook = ContractWebhook::sole();

    expect($webhook->workspace_id)->toBe($workspace->id)
        ->and($webhook->created_by)->toBe($user->id)
        // Stored in the order the model lists them, not the order the form sent
        // them: two subscriptions that differ only in that are two rows that
        // look different and are not.
        ->and($webhook->events)->toBe([ContractWebhook::EVENT_SIGNED, ContractWebhook::EVENT_COMPLETED])
        ->and($webhook->secret)->toStartWith('whs_')
        ->and($webhook->disabled_at)->toBeNull();
});

it('refuses an address on the inside of the network before it is ever stored', function () {
    [$user] = webhookBeheerder();

    actingAs($user)
        ->post(route('workspace.contract-webhooks.store'), [
            'name' => 'Localhost',
            'url' => 'http://127.0.0.1:8000/webhook',
            'events' => [ContractWebhook::EVENT_SIGNED],
        ])
        ->assertSessionHasErrors('url');

    expect(ContractWebhook::count())->toBe(0);
});

it('refuses a subscription that asked for nothing', function () {
    [$user] = webhookBeheerder();

    actingAs($user)
        ->post(route('workspace.contract-webhooks.store'), [
            'name' => 'Stil',
            'url' => 'https://93.184.216.34/webhook',
            'events' => [],
        ])
        ->assertSessionHasErrors('events');

    expect(ContractWebhook::count())->toBe(0);
});

it('switches one off and back on without minting a new secret', function () {
    [$user, $workspace] = webhookBeheerder();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);
    $secret = $webhook->secret;

    actingAs($user)
        ->patch(route('workspace.contract-webhooks.toggle', $webhook), ['enabled' => false])
        ->assertRedirect();

    expect($webhook->fresh()->disabled_at)->not->toBeNull();

    actingAs($user)
        ->patch(route('workspace.contract-webhooks.toggle', $webhook), ['enabled' => true])
        ->assertRedirect();

    /*
     * The secret survives, and that is the point of a switch rather than a
     * delete: stopping an integration for an afternoon must not cost somebody a
     * trip into the other system to paste a new value.
     */
    expect($webhook->fresh()->disabled_at)->toBeNull()
        ->and($webhook->fresh()->secret)->toBe($secret);
});

it('rotates a secret, and the old one stops signing anything', function () {
    [$user, $workspace] = webhookBeheerder();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);
    $was = $webhook->secret;

    actingAs($user)
        ->post(route('workspace.contract-webhooks.rotate', $webhook))
        ->assertRedirect();

    expect($webhook->fresh()->secret)->not->toBe($was)->toStartWith('whs_');
});

it('takes one away', function () {
    [$user, $workspace] = webhookBeheerder();

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->delete(route('workspace.contract-webhooks.destroy', $webhook))
        ->assertRedirect();

    expect(ContractWebhook::count())->toBe(0);
});

it('is closed to somebody who does not run the workspace', function () {
    [, $workspace] = webhookBeheerder();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    $webhook = ContractWebhook::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($member);

    get(route('workspace.contract-webhooks.index'))->assertForbidden();

    post(route('workspace.contract-webhooks.store'), [
        'name' => 'Van mij',
        'url' => 'https://93.184.216.34/webhook',
        'events' => [ContractWebhook::EVENT_SIGNED],
    ])->assertForbidden();

    patch(route('workspace.contract-webhooks.toggle', $webhook), ['enabled' => false])
        ->assertForbidden();

    delete(route('workspace.contract-webhooks.destroy', $webhook))->assertForbidden();

    expect(ContractWebhook::count())->toBe(1);
});

it('is closed to another workspace beheerder', function () {
    [$user] = webhookBeheerder();

    // Somebody else's subscription, reached by id from a workspace this person
    // does run. The route binds the model before anything has asked whose it is.
    $theirs = ContractWebhook::factory()->create();

    actingAs($user)
        ->delete(route('workspace.contract-webhooks.destroy', $theirs))
        ->assertNotFound();

    expect(ContractWebhook::whereKey($theirs->id)->exists())->toBeTrue();
});

it('is not there at all where contracts are switched off', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->get(route('workspace.contract-webhooks.index'))
        ->assertNotFound();
});
