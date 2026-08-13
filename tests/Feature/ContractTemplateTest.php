<?php

use App\Actions\Contracts\InstantiateTemplate;
use App\Actions\Contracts\SendContract;
use App\Actions\Contracts\SignContract;
use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Mail\ContractRequestMail;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * A contract kept to be sent again.
 *
 * The row looks like any other contract and is deliberately never treated like
 * one: it is not outstanding work, it cannot be sent, withdrawn or nudged, and
 * the one signature on it is the author's own — put there once, so that every
 * contract made from it can carry it.
 *
 * What these tests watch is that boundary. Everything that walks the contracts
 * table has to step around a template, and the ways of getting that wrong are
 * all quiet: a template counted as a draft somebody forgot to send, a nudge
 * mailed to yourself, or an author's signature spent on the one document it was
 * never meant to leave on.
 */
beforeEach(fn () => Queue::fake());

/**
 * A template with its document, its boxes and the author down as its one
 * signer, ready for them to put their name on it.
 *
 * Real bytes on the disk rather than a faked file, for the same reason
 * ContractCompletionTest writes them: signing checks the hash of what is on
 * disk against what was recorded, and a test that never wrote any could not
 * tell a matching document from a replaced one.
 *
 * @return array{0: Contract, 1: ContractSigner, 2: User}
 */
function templateAwaitingItsAuthor(int $requiredSigners = 1): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(ContractsFeature::class);

    $template = Contract::factory()->template($requiredSigners)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst',
        'page_count' => 1,
    ]);

    $template->addMedia(UploadedFile::fake()->create('huurovereenkomst.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $template->update([
        'source_hash' => hash_file('sha256', $template->source()->getPath()),
    ]);

    // Position zero is the author's, and everything the template will produce
    // depends on that: the recipients start at one. See Contract::templateSigner.
    $signer = ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
    ]);

    ContractField::factory()->signature()->forSigner(0)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening verhuurder',
    ]);

    return [$template->fresh(['fields', 'signers']), $signer, $author];
}

it('keeps a template out of the outstanding work', function () {
    $workspace = Workspace::factory()->create();

    Contract::factory()->template()->create(['workspace_id' => $workspace->id]);
    Contract::factory()->create(['workspace_id' => $workspace->id]);

    expect(Contract::query()->outstanding()->count())->toBe(1)
        ->and(Contract::query()->templates()->count())->toBe(1)
        ->and(Contract::query()->realContracts()->count())->toBe(1);
});

it('refuses to send the template itself', function () {
    [$template] = templateAwaitingItsAuthor();

    expect(fn () => app(SendContract::class)->handle($template, [
        ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
    ]))->toThrow(RuntimeException::class);

    expect($template->fresh()->status)->toBe(ContractStatus::Draft);
});

it('lets the author sign the template without finishing it', function () {
    [$template, $signer] = templateAwaitingItsAuthor();

    $signer->addMedia(UploadedFile::fake()->image('handtekening.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    // forceFill, because the columns that record what somebody did are kept out
    // of the fillable list — see StoreSignature, which writes them the same way.
    $signer->forceFill(['signature_method' => SignatureMethod::Drawn])->save();

    ContractFieldValue::factory()->drawn()->create([
        'contract_field_id' => $template->fields->first()->id,
        'contract_signer_id' => $signer->id,
    ]);

    app(SignContract::class)->handle($signer->fresh(), '127.0.0.1', 'Pest');

    $template->refresh();

    expect($signer->fresh()->hasSigned())->toBeTrue()
        // Still a draft, and still a template: the signature makes it usable,
        // not finished. A Completed template would be evidence of an agreement
        // nobody has been shown.
        ->and($template->status)->toBe(ContractStatus::Draft)
        ->and($template->completed_at)->toBeNull();

    Queue::assertNotPushed(RenderSignedContractJob::class);
});

it('opens the signing page for the author of a draft template', function () {
    [, $signer] = templateAwaitingItsAuthor();

    // A template is a draft forever, so the ordinary "is dit nog te tekenen"
    // rule would show its author a withdrawn contract.
    $this->get(route('contracts.sign.show', $signer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'signing'));
});

it('will not send a template that nobody has finished preparing', function () {
    [$template, $signer] = templateAwaitingItsAuthor();

    expect($template->isReadyToSend())->toBeFalse();

    $signer->forceFill(['signed_at' => now()])->save();

    expect($template->fresh(['fields', 'signers'])->isReadyToSend())->toBeTrue();
});

it('counts the author as a party alongside the recipients', function () {
    [$template] = templateAwaitingItsAuthor(2);

    expect($template->partyCount())->toBe(3);

    $template->signers->first()->delete();

    expect($template->fresh(['fields', 'signers'])->partyCount())->toBe(2);
});

it('refuses to withdraw or nudge a template', function () {
    [$template, , $author] = templateAwaitingItsAuthor();

    expect($author->can('cancel', $template))->toBeFalse()
        ->and($author->can('remind', $template))->toBeFalse();
});

/**
 * The same template, with its author's signature actually on it.
 *
 * @return array{0: Contract, 1: ContractSigner, 2: User}
 */
function templateItsAuthorHasSigned(int $requiredSigners = 1): array
{
    [$template, $signer, $author] = templateAwaitingItsAuthor($requiredSigners);

    $signer->addMedia(UploadedFile::fake()->image('handtekening.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    $signer->forceFill([
        'signed_at' => now()->subDay(),
        'signed_document_hash' => $template->source_hash,
        'signature_method' => SignatureMethod::Drawn,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Pest',
    ])->save();

    ContractFieldValue::factory()->drawn()->create([
        'contract_field_id' => $template->fields->first()->id,
        'contract_signer_id' => $signer->id,
    ]);

    return [$template->fresh(['fields', 'signers']), $signer->fresh(), $author];
}

it('makes a contract out of a template and carries the signature across', function () {
    [$template, $templateSigner, $author] = templateItsAuthorHasSigned();

    $contract = app(InstantiateTemplate::class)->handle($template, $author)->contract;

    expect($contract->is_template)->toBeFalse()
        ->and($contract->status)->toBe(ContractStatus::Draft)
        // The same bytes, so the same measurement — which is what makes the
        // author's signature on the copy mean anything at all.
        ->and($contract->source_hash)->toBe($template->source_hash)
        ->and($contract->fields)->toHaveCount(1);

    $carried = $contract->signers->first();

    expect($carried->hasSigned())->toBeTrue()
        ->and($carried->email)->toBe($templateSigner->email)
        ->and($carried->signature_method)->toBe(SignatureMethod::Drawn)
        ->and($carried->signature())->not->toBeNull()
        ->and($carried->values)->toHaveCount(1)
        // A credential for one contract. The template's link must not open it.
        ->and($carried->token)->not->toBe($templateSigner->token);
});

it('leaves the template exactly as it was', function () {
    [$template, $templateSigner, $author] = templateItsAuthorHasSigned();

    app(InstantiateTemplate::class)->handle($template, $author);

    $template->refresh();

    expect($template->is_template)->toBeTrue()
        ->and($template->status)->toBe(ContractStatus::Draft)
        ->and($template->signers)->toHaveCount(1)
        ->and($template->source())->not->toBeNull()
        ->and($templateSigner->fresh()->signature())->not->toBeNull();
});

it('puts the recipients behind the author and leaves the boxes where they were', function () {
    [$template, , $author] = templateItsAuthorHasSigned();

    ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening huurder',
    ]);

    $instantiate = app(InstantiateTemplate::class);

    $contract = $instantiate->handle($template->fresh(['fields', 'signers']), $author)->contract;

    app(SendContract::class)->handle($contract, $instantiate->roster($contract, [
        ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
    ]));

    $contract->refresh()->load(['signers', 'fields']);

    expect($contract->signers)->toHaveCount(2)
        ->and($contract->signers->first()->email)->toBe($author->email)
        ->and($contract->signers->first()->hasSigned())->toBeTrue()
        ->and($contract->signers->last()->email)->toBe('anna@example.com')
        ->and($contract->signers->last()->signing_order)->toBe(1)
        // The boxes still belong to the party they were drawn for: nothing was
        // renumbered by adding the recipient underneath the author.
        ->and($contract->fields->pluck('signer_index')->all())->toBe([0, 1])
        ->and($contract->status)->toBe(ContractStatus::Sent);
});

it('only invites the people who still have to do something', function () {
    [$template, , $author] = templateItsAuthorHasSigned();

    Mail::fake();

    $instantiate = app(InstantiateTemplate::class);
    $contract = $instantiate->handle($template, $author)->contract;

    app(SendContract::class)->handle($contract, $instantiate->roster($contract, [
        ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
    ]));

    // The author signed the template; asking them to sign the copy would be
    // asking for something they have already given.
    Mail::assertSent(ContractRequestMail::class, 1);
    Mail::assertNotSent(ContractRequestMail::class, fn (ContractRequestMail $mail): bool => $mail->hasTo($author->email));
});

it('refuses a template that is not finished being prepared', function () {
    [$template, , $author] = templateAwaitingItsAuthor();

    expect(fn () => app(InstantiateTemplate::class)->handle($template, $author))
        ->toThrow(RuntimeException::class);
});

it('refuses to instantiate something that is not a template', function () {
    $contract = Contract::factory()->create();
    $author = User::factory()->create();

    expect(fn () => app(InstantiateTemplate::class)->handle($contract, $author))
        ->toThrow(RuntimeException::class);
});

it('has no signature boxes to satisfy when the author does not sign along', function () {
    $template = Contract::factory()->template(2)->create();

    ContractField::factory()->ofType(ContractFieldType::Text)->forSigner(0)->create([
        'contract_id' => $template->id,
    ]);

    expect($template->fresh(['fields', 'signers'])->templateSigner())->toBeNull()
        ->and($template->fresh(['fields', 'signers'])->partyCount())->toBe(2);
});
