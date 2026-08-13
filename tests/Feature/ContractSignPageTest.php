<?php

use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The page behind the link, for somebody who may have no account at all.
 *
 * Two things are being guarded here and they pull in opposite directions. The
 * token has to be strict — looked up by token, never enumerable, throttled —
 * and at the same time a dead link has to explain itself, because the person
 * holding it was invited by name and telling them nothing is how somebody ends
 * up on the telephone. See ContractSignController for the longer version.
 */

/** @return array{0: Contract, 1: ContractSigner, 2: Workspace} */
function contractOutForSignature(array $contractState = [], array $signerState = []): array
{
    Storage::fake('local');

    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'page_count' => 2,
        ...$contractState,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $signer = ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        ...$signerState,
    ]);

    return [$contract, $signer, $workspace];
}

it('shows the contract to the person the link was made for', function () {
    [$contract, $signer] = contractOutForSignature();

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'label' => 'Naam huurder',
    ]);

    get(route('contracts.sign.show', $signer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('contracts/sign')
            ->where('state', 'signing')
            ->where('contract.signerName', $signer->name)
            ->where('contract.pageCount', 2)
            ->has('contract.fields', 1)
            ->where('contract.fields.0.label', 'Naam huurder')
            ->where('documentUrl', route('contracts.sign.document', $signer->token))
        );
});

it('never sends a token along in the payload', function () {
    [, $signer] = contractOutForSignature();

    $props = get(route('contracts.sign.show', $signer->token))
        ->viewData('page')['props'];

    /*
     * Only this signer's own token, which they already hold — it is in the
     * address bar. Nobody else's may be in here: on a contract with three
     * signers, one payload carrying all three tokens would let any of them sign
     * as the others.
     */
    expect(json_encode($props['contract']))->not->toContain($signer->token);
});

it('shows a signer only their own boxes', function () {
    [$contract, $mine] = contractOutForSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
    ]);

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'label' => 'Van mij',
    ]);
    ContractField::factory()->forSigner(1)->create([
        'contract_id' => $contract->id,
        'label' => 'Van de ander',
    ]);

    /*
     * Not because the other person's boxes are secret, but because a page
     * offering somebody four boxes of which two are not theirs is a page nobody
     * can fill in correctly.
     */
    get(route('contracts.sign.show', $mine->token))
        ->assertInertia(fn ($page) => $page
            ->has('contract.fields', 1)
            ->where('contract.fields.0.label', 'Van mij'));

    get(route('contracts.sign.show', $theirs->token))
        ->assertInertia(fn ($page) => $page
            ->has('contract.fields', 1)
            ->where('contract.fields.0.label', 'Van de ander'));
});

it('does not tell one signer who the others are', function () {
    [$contract, $signer] = contractOutForSignature();

    ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
        'name' => 'Bram Jansen',
    ]);

    $props = get(route('contracts.sign.show', $signer->token))
        ->viewData('page')['props'];

    /*
     * Counts rather than names. A contract sent to a landlord and three
     * prospective tenants would otherwise hand each of them the others'
     * identities.
     */
    expect($props['contract']['signerCount'])->toBe(2)
        ->and(json_encode($props['contract']))->not->toContain('Bram Jansen');
});

/**
 * What the people before you already put down.
 *
 * The rule underneath all five of these: a signed answer is shown, and nothing
 * else is. A draft can still be retyped or end in a refusal, so presenting one
 * to the next signer would show a guess as a commitment.
 */
it('shows what somebody who already signed filled in', function () {
    [$contract, $mine] = contractOutForSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->signed()->create([
        'contract_id' => $contract->id,
    ]);

    $field = ContractField::factory()->forSigner(1)->create([
        'contract_id' => $contract->id,
        'page' => 2,
        'label' => 'Naam verhuurder',
    ]);

    ContractFieldValue::factory()->create([
        'contract_field_id' => $field->id,
        'contract_signer_id' => $theirs->id,
        'value' => 'Anna de Vries',
    ]);

    get(route('contracts.sign.show', $mine->token))
        ->assertInertia(fn ($page) => $page
            // Still only this person's own boxes to fill in...
            ->has('contract.fields', 0)

            // ...and the contract as it now reads beside them.
            ->has('contract.filled', 1)
            ->where('contract.filled.0.value', 'Anna de Vries')
            ->where('contract.filled.0.page', 2)
            ->where('contract.filled.0.mark', null));
});

it('keeps a half-typed draft of somebody else off the page', function () {
    [$contract, $mine] = contractOutForSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
    ]);

    $field = ContractField::factory()->forSigner(1)->create([
        'contract_id' => $contract->id,
    ]);

    ContractFieldValue::factory()->draft()->create([
        'contract_field_id' => $field->id,
        'contract_signer_id' => $theirs->id,
        'value' => 'Nog aan het nadenken',
    ]);

    // They have not signed, so this is somebody thinking out loud.
    get(route('contracts.sign.show', $mine->token))
        ->assertInertia(fn ($page) => $page->has('contract.filled', 0));
});

it('points at another signer\'s mark without handing over their token', function () {
    [$contract, $mine] = contractOutForSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->signed()->create([
        'contract_id' => $contract->id,
    ]);

    $theirs->addMedia(UploadedFile::fake()->image('signature.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    $field = ContractField::factory()->forSigner(1)->signature()->create([
        'contract_id' => $contract->id,
    ]);

    ContractFieldValue::factory()->drawn()->create([
        'contract_field_id' => $field->id,
        'contract_signer_id' => $theirs->id,
    ]);

    $props = get(route('contracts.sign.show', $mine->token))
        ->viewData('page')['props'];

    expect($props['contract']['filled'])->toHaveCount(1)
        ->and($props['contract']['filled'][0]['mark'])->toContain($mine->token)

        /*
         * The other person's token is permission to sign as them. A picture of
         * their signature is not, so the address carries the reader's own.
         */
        ->and(json_encode($props['contract']))->not->toContain($theirs->token);

    get(route('contracts.sign.mark.show', [
        'token' => $mine->token,
        'signer' => $theirs->id,
        'kind' => 'signature',
    ]))->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('will not hand over the mark of somebody who has not signed yet', function () {
    [$contract, $mine] = contractOutForSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
    ]);

    $theirs->addMedia(UploadedFile::fake()->image('signature.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    // Drawn in a draft, and a draft can still be cleared and drawn again.
    get(route('contracts.sign.mark.show', [
        'token' => $mine->token,
        'signer' => $theirs->id,
        'kind' => 'signature',
    ]))->assertNotFound();
});

it('will not hand over a mark from a different contract', function () {
    [, $mine] = contractOutForSignature();
    [, $stranger] = contractOutForSignature([], ['signed_at' => now()->subDay()]);

    $stranger->addMedia(UploadedFile::fake()->image('signature.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    // A token is permission to see one document, never a key to the table.
    get(route('contracts.sign.mark.show', [
        'token' => $mine->token,
        'signer' => $stranger->id,
        'kind' => 'signature',
    ]))->assertNotFound();
});

it('records the moment the link was first opened', function () {
    [, $signer] = contractOutForSignature();

    expect($signer->opened_at)->toBeNull();

    get(route('contracts.sign.show', $signer->token))->assertOk();

    $opened = $signer->fresh()->opened_at;

    expect($opened)->not->toBeNull();

    // Once, not on every visit: "hij heeft het gezien" is a fact about the
    // first time, and refreshing must not keep moving it.
    get(route('contracts.sign.show', $signer->token));

    expect($signer->fresh()->opened_at->equalTo($opened))->toBeTrue();
});

it('answers a token nobody holds with a plain 404', function () {
    contractOutForSignature();

    // Nothing to enumerate, and nothing said about what might have been there.
    get(route('contracts.sign.show', str_repeat('a', 64)))->assertNotFound();
});

it('disappears when the workspace switches contracts off', function () {
    [, $signer, $workspace] = contractOutForSignature();

    Feature::for($workspace)->deactivate(ContractsFeature::class);

    get(route('contracts.sign.show', $signer->token))->assertNotFound();
});

/**
 * The four dead ends, each with its own screen.
 *
 * A dataset rather than four near-identical tests: what is being checked is one
 * rule — that the reason reaches the person — applied to four states.
 */
it('explains why the link no longer works, rather than answering 404', function (
    array $contractState,
    array $signerState,
    string $expected,
) {
    [, $signer] = contractOutForSignature($contractState, $signerState);

    get(route('contracts.sign.show', $signer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', $expected));
})->with([
    'already signed' => [[], ['signed_at' => '2026-08-01 10:00:00'], 'signed'],
    'turned it down' => [[], ['declined_at' => '2026-08-01 10:00:00'], 'declined'],
    'withdrawn' => [['status' => ContractStatus::Cancelled], [], 'cancelled'],
    'expired' => [['expires_at' => '2026-08-01 10:00:00'], [], 'expired'],
]);

it('tells somebody they signed even after the deadline has passed', function () {
    [, $signer] = contractOutForSignature(
        ['expires_at' => now()->subDay()],
        ['signed_at' => now()->subWeek()],
    );

    /*
     * What they did is the more useful fact, and "verlopen" would read as
     * though their signature had not counted.
     */
    get(route('contracts.sign.show', $signer->token))
        ->assertInertia(fn ($page) => $page->where('state', 'signed'));
});

it('hands over the document behind the token, with nosniff', function () {
    [, $signer] = contractOutForSignature();

    get(route('contracts.sign.document', $signer->token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('still lets somebody read what they signed', function () {
    [, $signer] = contractOutForSignature([], ['signed_at' => now()->subDay()]);

    // Refusing them their own copy the moment the ink dries is the wrong lesson.
    get(route('contracts.sign.document', $signer->token))->assertOk();
});

it('keeps what was typed so far', function () {
    [$contract, $signer] = contractOutForSignature();

    $field = ContractField::factory()->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'Anna de Vries'],
    ])->assertRedirect();

    $value = ContractFieldValue::sole();

    expect($value->value)->toBe('Anna de Vries')
        ->and($value->contract_signer_id)->toBe($signer->id)

        /*
         * Not stamped as answered. A draft records what was typed; filled_at is
         * what turns a value into an answer, and that happens once, at signing.
         */
        ->and($value->filled_at)->toBeNull();
});

it('updates the draft rather than laying a second row beside it', function () {
    [$contract, $signer] = contractOutForSignature();

    $field = ContractField::factory()->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'Anna'],
    ]);
    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'Anna de Vries'],
    ]);

    // The unique index is what makes autosaving safe to call as often as
    // somebody types.
    expect(ContractFieldValue::count())->toBe(1)
        ->and(ContractFieldValue::sole()->value)->toBe('Anna de Vries');
});

it('asks nothing of a draft that is only half filled in', function () {
    [$contract, $signer] = contractOutForSignature();

    $field = ContractField::factory()->create([
        'contract_id' => $contract->id,
        'is_required' => true,
    ]);

    /*
     * The whole point of a draft: somebody stops halfway through a long
     * contract to look something up. "Verplicht" is asked once, at the end.
     */
    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => ''],
    ])->assertSessionHasNoErrors();
});

it('still refuses a date that is not a date', function () {
    [$contract, $signer] = contractOutForSignature();

    $field = ContractField::factory()->ofType(ContractFieldType::Date)->create([
        'contract_id' => $contract->id,
    ]);

    // Forgiving about what is missing, not about what is wrong.
    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'volgende week dinsdag'],
    ])->assertSessionHasErrors('values.'.$field->id);
});

it('ignores a value aimed at somebody else\'s box', function () {
    [$contract, $signer] = contractOutForSignature();

    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    $theirs = ContractField::factory()->forSigner(1)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$theirs->id => 'Niet van mij'],
    ])->assertRedirect();

    // Dropped rather than refused: it is not a field this signer has, so there
    // is nothing to validate against and nothing to write.
    expect(ContractFieldValue::count())->toBe(0);
});

it('will not take a signature typed into the network tab', function () {
    [$contract, $signer] = contractOutForSignature();

    $field = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'Anna de Vries'],
    ])->assertSessionHasErrors('values.'.$field->id);

    // A drawn field's answer is an image on the signer, and never a string.
    expect(ContractFieldValue::count())->toBe(0);
});

it('refuses to keep a draft once the link is spent', function () {
    [$contract, $signer] = contractOutForSignature([], ['signed_at' => now()->subHour()]);

    $field = ContractField::factory()->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$field->id => 'Te laat'],
    ])->assertStatus(409);

    expect(ContractFieldValue::count())->toBe(0);
});
