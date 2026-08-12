<?php

use App\Actions\Contracts\SignContract;
use App\Actions\Contracts\SigningRefused;
use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\post;

/**
 * The one step that cannot be taken back.
 *
 * Everything before this is reversible — a draft can be edited, a box can be
 * moved, a half-filled page abandoned. So this is where every guarantee the
 * feature makes has to actually hold: that the document is the one that was
 * sent, that nothing required was skipped, and that nobody signs twice.
 */

/*
 * The queue is held rather than run.
 *
 * Signing a contract now dispatches the job that composes the signed copy, and
 * on the sync connection this suite runs on that job would execute inside the
 * request — turning every test here into a test of the PDF renderer as well.
 * What these tests are about is the signing, and RenderSignedContractTest
 * covers both the dispatch and what the job does with it.
 */
beforeEach(fn () => Queue::fake());

/**
 * A contract with a real file behind it, so its hash means something.
 *
 * A faked file would do for most of this suite and not for the part that
 * matters: the hash check compares what is on disk against what was recorded,
 * and a test that never wrote bytes could not tell the two apart.
 *
 * @return array{0: Contract, 1: ContractSigner}
 */
function contractReadyToSign(array $signerState = []): array
{
    Storage::fake('local');

    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'page_count' => 1,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    // The hash as the upload would have recorded it. See NormalisePdf.
    $contract->update([
        'source_hash' => hash_file('sha256', $contract->source()->getPath()),
    ]);

    $signer = ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        ...$signerState,
    ]);

    return [$contract->fresh(), $signer];
}

/** Fill in one text box for this signer, the way the draft endpoint would. */
function fillOneField(Contract $contract, ContractSigner $signer): ContractField
{
    $field = ContractField::factory()->create(['contract_id' => $contract->id]);

    ContractFieldValue::factory()->draft()->create([
        'contract_field_id' => $field->id,
        'contract_signer_id' => $signer->id,
        'value' => 'Anna de Vries',
    ]);

    return $field;
}

it('records who signed, when, from where and under what', function () {
    [$contract, $signer] = contractReadyToSign();

    fillOneField($contract, $signer);

    post(route('contracts.sign.complete', $signer->token), [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $signer->refresh();

    expect($signer->signed_at)->not->toBeNull()
        ->and($signer->ip_address)->not->toBeNull()
        ->and($signer->user_agent)->toBe('Mozilla/5.0 (iPhone)')

        /*
         * The measurement that makes the signature mean anything. "Het
         * contract" is not a thing that can be pointed at later — the row can
         * be edited, the file replaced — so the hash taken at this moment is
         * the only version of the claim that does not depend on anything
         * staying still.
         */
        ->and($signer->signed_document_hash)->toBe($contract->source_hash);
});

it('turns the draft values into answers', function () {
    [$contract, $signer] = contractReadyToSign();

    $field = fillOneField($contract, $signer);

    expect(ContractFieldValue::sole()->filled_at)->toBeNull();

    post(route('contracts.sign.complete', $signer->token));

    /*
     * A draft records what was typed; this is what turns it into an answer. Up
     * to this moment "leeg gelaten" and "niet langs geweest" are different
     * things, and this is the moment that stops mattering.
     */
    expect(ContractFieldValue::where('contract_field_id', $field->id)
        ->sole()->filled_at)->not->toBeNull();
});

it('closes the contract once the last person has answered', function () {
    [$contract, $first] = contractReadyToSign();

    $second = ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.complete', $first->token));

    // One of two: still waiting.
    expect($contract->fresh())
        ->status->toBe(ContractStatus::Sent)
        ->completed_at->toBeNull();

    post(route('contracts.sign.complete', $second->token));

    expect($contract->fresh())
        ->status->toBe(ContractStatus::Completed)
        ->completed_at->not->toBeNull();
});

it('refuses to sign while a required box is still empty', function () {
    [$contract, $signer] = contractReadyToSign();

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'label' => 'Naam huurder',
        'is_required' => true,
    ]);

    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing');

    expect($signer->fresh()->signed_at)->toBeNull();
});

it('names the boxes that are still open', function () {
    [$contract, $signer] = contractReadyToSign();

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'label' => 'Naam huurder',
    ]);

    // Telling somebody "er ontbreekt iets" on a twenty-page contract is not an
    // answer they can act on.
    $errors = post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing')
        ->getSession()
        ->get('errors');

    expect($errors->first('signing'))->toContain('Naam huurder');
});

it('will not sign without the signature it asked for', function () {
    [$contract, $signer] = contractReadyToSign();

    ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);

    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing');

    // And goes through once the mark is down.
    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => UploadedFile::fake()->image('signature.png', 300, 100),
    ]);

    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasNoErrors();

    expect($signer->fresh()->signed_at)->not->toBeNull();
});

it('stops somebody who cleared their signature again', function () {
    [$contract, $signer] = contractReadyToSign();

    ContractField::factory()->signature()->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => UploadedFile::fake()->image('signature.png', 300, 100),
    ]);

    \Pest\Laravel\delete(route('contracts.sign.signature.clear', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
    ]);

    /*
     * Nobody wrote a rule for this: clearing takes filled_at back off, and the
     * completeness check reads filled_at. The honest answer falls out.
     */
    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing');
});

it('refuses when the document is not the one that was sent', function () {
    [$contract, $signer] = contractReadyToSign();

    fillOneField($contract, $signer);

    // Somebody, somehow, put different bytes behind the same contract.
    file_put_contents($contract->source()->getPath(), 'iets heel anders');

    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing');

    /*
     * Nothing in the application does this, and that is exactly why it has to
     * stop everything rather than be logged and shrugged at.
     */
    expect($signer->fresh()->signed_at)->toBeNull();
});

it('lets nobody sign twice', function () {
    [$contract, $signer] = contractReadyToSign();

    fillOneField($contract, $signer);

    post(route('contracts.sign.complete', $signer->token))->assertSessionHasNoErrors();

    $signedAt = $signer->fresh()->signed_at;

    post(route('contracts.sign.complete', $signer->token))
        ->assertSessionHasErrors('signing');

    // The second one refuses rather than quietly overwriting the first one's
    // timestamp and IP address.
    expect($signer->fresh()->signed_at->equalTo($signedAt))->toBeTrue();
});

it('claims the row in one statement, so two at once cannot both win', function () {
    [$contract, $signer] = contractReadyToSign();

    fillOneField($contract, $signer);

    $sign = app(SignContract::class);

    $sign->handle($signer, '198.51.100.4');

    /*
     * The second call is handed the *stale* model — the one that still thinks
     * signed_at is null, which is exactly the state a second concurrent request
     * would be holding. What stops it is the where clause in the update, not
     * anything this object knows.
     */
    expect(fn () => $sign->handle($signer, '198.51.100.9'))
        ->toThrow(SigningRefused::class);

    expect($signer->fresh()->ip_address)->toBe('198.51.100.4');
});

it('takes a refusal as an answer, with the reason', function () {
    [, $signer] = contractReadyToSign();

    post(route('contracts.sign.decline', $signer->token), [
        'reason' => 'Niet akkoord met artikel 4.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($signer->fresh())
        ->declined_at->not->toBeNull()
        ->decline_reason->toBe('Niet akkoord met artikel 4.')
        ->signed_at->toBeNull()

        /*
         * No address and no browser. Those are on the row to support a
         * signature, and a refusal is a claim about nothing — there is no
         * document bearing this person's name.
         */
        ->ip_address->toBeNull();
});

it('takes a refusal without a reason', function () {
    [, $signer] = contractReadyToSign();

    post(route('contracts.sign.decline', $signer->token))->assertSessionHasNoErrors();

    expect($signer->fresh()->declined_at)->not->toBeNull();
});

it('closes a contract that everybody turned down', function () {
    [$contract, $signer] = contractReadyToSign();

    post(route('contracts.sign.decline', $signer->token), ['reason' => 'Nee.']);

    /*
     * A refusal finishes it just as a signature does. Leaving it open would
     * keep telling the author that somebody still owes them something.
     */
    expect($contract->fresh()->status)->toBe(ContractStatus::Completed);
});

it('will not let somebody sign after refusing', function () {
    [$contract, $signer] = contractReadyToSign();

    fillOneField($contract, $signer);

    post(route('contracts.sign.decline', $signer->token));
    post(route('contracts.sign.complete', $signer->token))->assertSessionHasErrors('signing');

    expect($signer->fresh()->signed_at)->toBeNull();
});

it('will not let the database hold both outcomes at once', function () {
    [, $signer] = contractReadyToSign();

    /*
     * Reached round the back, the way a tinker session or a controller written
     * next year would. The application refuses this on every route it has; the
     * constraint is what makes that true of routes nobody has written yet.
     */
    expect(fn () => ContractSigner::query()->whereKey($signer->id)->update([
        'signed_at' => now(),
        'declined_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses both endings once the contract is withdrawn', function () {
    [$contract, $signer] = contractReadyToSign();

    $contract->update(['status' => ContractStatus::Cancelled, 'cancelled_at' => now()]);

    post(route('contracts.sign.complete', $signer->token))->assertSessionHasErrors('signing');
    post(route('contracts.sign.decline', $signer->token))->assertSessionHasErrors('signing');

    expect($signer->fresh())
        ->signed_at->toBeNull()
        ->declined_at->toBeNull();
});
