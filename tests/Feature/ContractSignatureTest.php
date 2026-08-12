<?php

use App\Enums\ContractFieldType;
use App\Enums\SignatureMethod;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The mark that stands for somebody's name.
 *
 * What is being guarded here is narrower than it looks. The picture itself is
 * made in the browser and this endpoint cannot know whether it is a signature
 * or a scribble — what it can and must know is that it is a small PNG, that it
 * belongs to the person holding this token, that it lands on the private disk,
 * and that putting it down marks the right boxes as answered and nobody else's.
 */

/** @return array{0: Contract, 1: ContractSigner} */
function contractAwaitingSignature(array $signerState = []): array
{
    Storage::fake('local');

    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'page_count' => 2,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $signer = ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        ...$signerState,
    ]);

    return [$contract, $signer];
}

/** A PNG the validator will accept, small enough to be a signature. */
function signatureImage(): UploadedFile
{
    return UploadedFile::fake()->image('signature.png', 300, 100);
}

it('puts down a drawn signature and records that it was drawn', function () {
    [, $signer] = contractAwaitingSignature();

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $signer->refresh();

    expect($signer->signature())->not->toBeNull()
        ->and($signer->signature()->disk)->toBe('local')
        ->and($signer->signature_method)->toBe(SignatureMethod::Drawn)

        /*
         * Null for a drawn one. There is no typed name to keep, and storing the
         * signer's own name here would make the audit trail claim something
         * nobody typed.
         */
        ->and($signer->signature_text)->toBeNull();
});

it('keeps the name as typed beside a typed signature', function () {
    [, $signer] = contractAwaitingSignature();

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Typed->value,
        'typed' => 'Anna de Vries',
        'image' => signatureImage(),
    ])->assertRedirect();

    /*
     * A picture of text is not text. The audit trail has to be able to say
     * "hij typte Anna de Vries", and it cannot read that back out of a PNG.
     */
    expect($signer->fresh())
        ->signature_method->toBe(SignatureMethod::Typed)
        ->signature_text->toBe('Anna de Vries');
});

it('marks every signature box of this signer as answered at once', function () {
    [$contract, $signer] = contractAwaitingSignature();

    $first = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);
    $second = ContractField::factory()->signature()->onPage(2)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    /*
     * A drawn field's value row carries nothing but filled_at — the image
     * hangs on the signer — so this stamp is the whole of what makes a
     * signature box count as answered.
     */
    foreach ([$first, $second] as $field) {
        expect(ContractFieldValue::where('contract_field_id', $field->id)
            ->where('contract_signer_id', $signer->id)
            ->sole()->filled_at)->not->toBeNull();
    }
});

it('leaves the other signer\'s boxes alone', function () {
    [$contract, $mine] = contractAwaitingSignature();

    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    $theirs = ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.signature', $mine->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    // Stamping the other person's box would report them as having signed.
    expect(ContractFieldValue::where('contract_field_id', $theirs->id)->count())->toBe(0);
});

it('does not let a signature stand in for initials', function () {
    [$contract, $signer] = contractAwaitingSignature();

    $initials = ContractField::factory()->ofType(ContractFieldType::Initials)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    /*
     * Two collections, kept apart because they are not the same drawing: a full
     * signature scaled down into a corner is a smudge.
     */
    expect($signer->fresh()->initials())->toBeNull()
        ->and(ContractFieldValue::where('contract_field_id', $initials->id)->count())->toBe(0);
});

it('replaces a mark rather than piling them up', function () {
    [, $signer] = contractAwaitingSignature();

    foreach ([1, 2] as $ignored) {
        post(route('contracts.sign.signature', $signer->token), [
            'kind' => ContractFieldType::Signature->value,
            'method' => SignatureMethod::Drawn->value,
            'image' => signatureImage(),
        ]);
    }

    // Drawing again means "dat was niet goed", not "ik heb er nu twee".
    expect($signer->fresh()->getMedia(ContractSigner::SIGNATURE))->toHaveCount(1);
});

it('clears the mark and every box it filled', function () {
    [$contract, $signer] = contractAwaitingSignature();

    $field = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    delete(route('contracts.sign.signature.clear', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
    ])->assertRedirect();

    expect($signer->fresh()->signature())->toBeNull()
        ->and(ContractFieldValue::where('contract_field_id', $field->id)
            ->sole()->filled_at)->toBeNull();
});

it('refuses anything that is not a small png', function () {
    [, $signer] = contractAwaitingSignature();

    // A signature is a few strokes. Anything approaching a megabyte is a
    // photograph, and a photograph of a passport is not what this box is for.
    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => UploadedFile::fake()->create('scan.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('image');

    expect($signer->fresh()->signature())->toBeNull();
});

it('refuses a mark on a box that is not drawn at all', function () {
    [, $signer] = contractAwaitingSignature();

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Text->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ])->assertStatus(422);
});

it('refuses a mark once the link is spent', function () {
    [, $signer] = contractAwaitingSignature(['signed_at' => now()->subHour()]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ])->assertStatus(409);
});

it('serves the mark back behind the same token, and never from cache', function () {
    [, $signer] = contractAwaitingSignature();

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    get(route('contracts.sign.signature.show', [
        'token' => $signer->token,
        'kind' => ContractFieldType::Signature->value,
    ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')

        /*
         * A browser holding the old drawing would show somebody the signature
         * they just replaced — on the page where they are deciding whether to
         * commit to it.
         */
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('will not hand one signer another signer\'s mark', function () {
    [$contract, $mine] = contractAwaitingSignature();

    $theirs = ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.signature', $mine->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    /*
     * The route is keyed by token, so this asks for the other person's own mark
     * — which does not exist. A picture of somebody's name is exactly the thing
     * that must not be reachable by anybody holding a different link.
     */
    get(route('contracts.sign.signature.show', [
        'token' => $theirs->token,
        'kind' => ContractFieldType::Signature->value,
    ]))->assertNotFound();
});

it('tells the page where this signer\'s marks are, and only theirs', function () {
    [$contract, $signer] = contractAwaitingSignature();

    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => signatureImage(),
    ]);

    $props = get(route('contracts.sign.show', $signer->token))
        ->viewData('page')['props'];

    $marks = $props['contract']['marks'];

    expect($marks[ContractFieldType::Signature->value])->toContain($signer->token)
        // Initials were never put down, so there is nothing to show for them.
        ->and($marks[ContractFieldType::Initials->value])->toBeNull();
});
