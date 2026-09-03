<?php

use App\Actions\Chat\PresentMessage;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The card that grows out of a contract link in a channel.
 *
 * Two things are being guarded. What the card says — counts and never names,
 * because who has signed and who has not is a list of people at different
 * stages of agreeing to something, and putting it under a message shows the
 * channel exactly who is holding things up.
 *
 * And where its one link lands, which the card cannot decide: it is drawn once
 * and broadcast to everybody at the same moment, so the choice is made by the
 * controller behind the URL.
 */

/** @return array{0: Message, 1: Contract, 2: User, 3: Workspace} */
function messageWithContractLink(array $state = []): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        ...$state,
    ]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        'body' => 'Graag tekenen: '.route('chat.contracts.show', [$workspace->slug, $contract->id]),
    ]);

    return [$message, $contract->refresh(), $author, $workspace];
}

it('draws the contract and how far it has got', function () {
    [$message, $contract, , $workspace] = messageWithContractLink([
        'expires_at' => now()->addDays(14),
    ]);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    $card = app(PresentMessage::class)->handle($message->fresh())['contractCard'];

    expect($card['id'])->toBe($contract->id)
        ->and($card['title'])->toBe('Huurovereenkomst 2026')
        ->and($card['signerCount'])->toBe(2)
        ->and($card['signedCount'])->toBe(1)
        ->and($card['state'])->toBe('sent')
        ->and($card['url'])->toBe(route('chat.contracts.show', [$workspace->id, $contract->id]));
});

it('never says who signed and who did not', function () {
    [$message, $contract] = messageWithContractLink();

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
        'email' => 'anna@example.com',
    ]);
    ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
        'name' => 'Bram Jansen',
    ]);

    $card = app(PresentMessage::class)->handle($message->fresh())['contractCard'];

    /*
     * Counts are what somebody reading the channel needs. Naming the one who
     * has not signed yet would be telling their colleagues who is holding
     * things up, which is a thing to say to a person directly.
     */
    expect(json_encode($card))
        ->not->toContain('Anna de Vries')
        ->not->toContain('Bram Jansen')
        ->not->toContain('anna@example.com');
});

it('reads the deadline itself rather than trusting the column', function () {
    [$message] = messageWithContractLink([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->subHour(),
    ]);

    /*
     * The column still says Sent until the nightly command has been round. A
     * card that said "verstuurd" about a contract nobody can sign any more
     * would be inviting somebody to go and try.
     */
    expect(app(PresentMessage::class)->handle($message->fresh())['contractCard']['state'])
        ->toBe('expired');
});

it('tells a finished contract from a withdrawn one', function () {
    [$done] = messageWithContractLink([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
    ]);
    [$stopped] = messageWithContractLink([
        'status' => ContractStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    expect(app(PresentMessage::class)->handle($done->fresh())['contractCard']['state'])
        ->toBe('completed');
    expect(app(PresentMessage::class)->handle($stopped->fresh())['contractCard']['state'])
        ->toBe('cancelled');
});

it('draws nothing for a contract from another workspace', function () {
    [$message] = messageWithContractLink();

    $elsewhere = Contract::factory()->sent()->create();

    $message->update([
        'body' => route('chat.contracts.show', ['other-workspace', $elsewhere->id]),
    ]);

    // The same fence the transfer and secret cards stand behind: an id from
    // somewhere else must not draw a card in this channel.
    expect(app(PresentMessage::class)->handle($message->fresh())['contractCard'])->toBeNull();
});

it('draws nothing when the message holds no such link', function () {
    [$message] = messageWithContractLink();

    $message->update(['body' => 'Gewoon een bericht zonder link.']);

    expect(app(PresentMessage::class)->handle($message->fresh())['contractCard'])->toBeNull();
});

it('sends a signer who still has something to do to their own page', function () {
    [, $contract, , $workspace] = messageWithContractLink();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    $signer = ContractSigner::factory()->forUser($colleague)->create([
        'contract_id' => $contract->id,
    ]);

    /*
     * Their own token, not the contract's id: the signing page has no notion of
     * a session, because most people who reach it have no account.
     */
    actingAs($colleague)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertRedirect(route('contracts.sign.show', $signer->token));
});

it('sends the author to the contract itself', function () {
    [, $contract, $author, $workspace] = messageWithContractLink();

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
    ]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contract-show')
            ->where('contract.signedCount', 1)
            ->where('contract.signerCount', 2)
            // Here the names *are* carried: this screen is behind the policy,
            // and knowing who has not signed is why somebody opened it.
            ->where('contract.signers.0.name', 'Anna de Vries')
            ->where('contract.signers.0.state', 'signed')
            ->where('contract.signers.1.state', 'waiting'));
});

it('sends a signer who has already answered to the contract, not back to the form', function () {
    [, $contract, , $workspace] = messageWithContractLink();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    ContractSigner::factory()->forUser($colleague)->signed()->create([
        'contract_id' => $contract->id,
    ]);

    /*
     * Nothing left for them to do on their own page, and they are not the
     * author either — so the policy is what answers, and it says no.
     */
    actingAs($colleague)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertForbidden();
});

it('keeps a colleague who has nothing to do with it out', function () {
    [, $contract, , $workspace] = messageWithContractLink();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    actingAs($colleague)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertForbidden();
});

it('shows the same card however the message reaches the browser', function () {
    [$message, $contract] = messageWithContractLink();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    $present = app(PresentMessage::class);

    /*
     * The bead's own warning, made into a test: the broadcast payload and the
     * Inertia payload both come out of PresentMessage, and if they could ever
     * differ a message would change appearance the moment somebody reloaded
     * the page. Presenting the same message twice has to give the same card.
     */
    expect($present->handle($message->fresh())['contractCard'])
        ->toEqual($present->handle($message->fresh())['contractCard']);

    // And nothing in it is per-viewer: one url for everybody, no "your" fields.
    expect(array_keys(app(PresentMessage::class)->handle($message->fresh())['contractCard']))
        ->toBe(['id', 'title', 'signerCount', 'signedCount', 'expiresAt', 'state', 'url']);
});
