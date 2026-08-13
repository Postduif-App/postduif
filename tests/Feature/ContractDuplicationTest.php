<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * Using a contract again, for other people.
 *
 * The one thing left to do with a document that has been signed: editing it has
 * been refused since the first signature landed, so the way to send the same
 * lease to next month's tenant is to make a fresh draft of it.
 *
 * What every test here is really watching is the line between the two rows. The
 * copy takes the document and the layout; it must take none of the history, and
 * the original must come out of it untouched.
 */

/** @return array{0: User, 1: Workspace, 2: Contract} */
function reusableContract(array $state = []): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->completed()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        'message' => 'Graag voor vrijdag tekenen.',
        'page_count' => 3,
        ...$state,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('huurovereenkomst.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
        'email' => 'anna@example.com',
    ]);

    return [$author, $workspace, $contract];
}

it('makes a fresh draft with the same document and the same boxes', function () {
    [$author, $workspace, $contract] = reusableContract();

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'page' => 2,
        'x' => 0.2,
        'y' => 0.4,
        'label' => 'Naam huurder',
        'position' => 0,
    ]);

    ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $contract->id,
        'page' => 3,
        'label' => 'Handtekening huurder',
        'position' => 1,
    ]);

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Huurovereenkomst 2026 — Kerkstraat 12',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    expect($copy->title)->toBe('Huurovereenkomst 2026 — Kerkstraat 12')
        ->and($copy->message)->toBe('Graag voor vrijdag tekenen.')
        ->and($copy->status)->toBe(ContractStatus::Draft)
        ->and($copy->page_count)->toBe(3)
        ->and($copy->workspace_id)->toBe($workspace->id)

        /*
         * The same hash, because the bytes are duplicated rather than re-made.
         * Re-running Ghostscript would give a rewrite of a rewrite — a
         * different document, which is the one thing a duplicate is not.
         */
        ->and($copy->source_hash)->toBe($contract->source_hash);

    // The boxes, geometry and owner and all.
    expect($copy->fields()->count())->toBe(2);

    $signature = $copy->fields()->where('label', 'Handtekening huurder')->sole();

    expect($signature->page)->toBe(3)
        ->and($signature->signer_index)->toBe(1);

    $text = $copy->fields()->where('label', 'Naam huurder')->sole();

    expect((float) $text->x)->toBe(0.2)
        ->and((float) $text->y)->toBe(0.4);
});

it('copies the PDF onto the new row rather than sharing one file', function () {
    [$author, $workspace, $contract] = reusableContract();

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Nieuwe huurder',
        ])
        ->assertRedirect();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    expect($copy->source())->not->toBeNull()
        ->and($copy->source()->file_name)->toBe('huurovereenkomst.pdf');

    /*
     * Two files on the disk, not two rows pointing at one. A shared path would
     * mean deleting either contract takes the document out from under the
     * other — and the media library's own delete does exactly that, without
     * asking who else is looking.
     *
     * Asked of the paths rather than of the disk, because the fake disk is one
     * directory shared by six parallel workers: a test that stats a file there
     * is racing whoever calls Storage::fake next.
     */
    expect($copy->source()->getPath())->not->toBe($contract->source()->getPath());

    $path = $copy->source()->getPath();

    $contract->delete();

    // Still its own row, still pointing where it did. Nothing about deleting
    // the original went near it.
    expect($copy->source())->not->toBeNull()
        ->and($copy->source()->getPath())->toBe($path);
});

it('leaves the history of the original behind', function () {
    [$author, $workspace, $contract] = reusableContract();

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Nog een keer',
        ])
        ->assertRedirect();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    /*
     * Nobody has been asked anything yet. Carrying a signer across would be
     * claiming somebody signed a document they have never seen — and carrying
     * their token across would hand a live link to a contract they answered
     * weeks ago.
     */
    expect($copy->signers()->count())->toBe(0)
        ->and($copy->completed_at)->toBeNull()
        ->and($copy->expires_at)->toBeNull()
        ->and($copy->notify_channel_id)->toBeNull()
        ->and($copy->signedCopy())->toBeNull();

    // And the original is exactly where it was. It is evidence.
    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Completed)
        ->and($contract->title)->toBe('Huurovereenkomst 2026')
        ->and($contract->signers()->count())->toBe(1);
});

it('puts the copy in the name of whoever pressed the button', function () {
    [, $workspace, $contract] = reusableContract();

    $manager = User::factory()->create();
    joinWorkspace($workspace, $manager, SystemRole::Admin);

    actingAs($manager)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Van de beheerder',
        ])
        ->assertRedirect();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    /*
     * Theirs rather than the original author's: they are the one who will be
     * chasing these signatures, and a draft in somebody else's name is a draft
     * that person finds in their list without knowing why.
     */
    expect($copy->created_by)->toBe($manager->id);
});

it('lands on the new contract, where the signers are named', function () {
    [$author, $workspace, $contract] = reusableContract();

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Volgende huurder',
        ])
        ->assertRedirect();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $copy]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contract-show')
            ->where('contract.title', 'Volgende huurder')

            // The panel that asks who this one goes to.
            ->where('can.send', true));
});

it('offers the copy on a contract that can no longer be changed', function () {
    [$author, $workspace, $contract] = reusableContract();

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Editing is long gone — somebody has signed it. This is what is
            // left, and it is the reason the button exists.
            ->where('can.update', false)
            ->where('can.duplicate', true));
});

it('needs a name for the copy', function () {
    [$author, $workspace, $contract] = reusableContract();

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [])
        ->assertSessionHasErrors('title');

    expect(Contract::query()->count())->toBe(1);
});

it('has nothing to copy when the document never arrived', function () {
    [$author, $workspace, $contract] = reusableContract();

    $contract->getFirstMedia(Contract::SOURCE)->delete();

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace->refresh(), $contract->fresh()]), [
            'title' => 'Zonder document',
        ])
        ->assertNotFound();

    expect(Contract::query()->count())->toBe(1);
});

it('refuses somebody who may see the contract but may not send any', function () {
    [, $workspace, $contract] = reusableContract();

    /*
     * A manager sees every contract in the workspace — that is how a contract
     * sent to the wrong address stays stoppable — but seeing one is not being
     * allowed to start one. What comes out of this is a new contract.
     */
    $manager = User::factory()->create();
    joinWorkspace($workspace, $manager, SystemRole::Admin);
    setAbility($workspace, WorkspaceAbility::SendContracts, false, SystemRole::Admin);

    actingAs($manager)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Mag niet',
        ])
        ->assertForbidden();

    expect(Contract::query()->count())->toBe(1);
});

it('refuses a member who has nothing to do with it', function () {
    [, $workspace, $contract] = reusableContract();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);
    setAbility($workspace, WorkspaceAbility::SendContracts, true, SystemRole::Member);

    actingAs($other)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Niet van mij',
        ])
        ->assertForbidden();

    expect(Contract::query()->count())->toBe(1);
});

it('refuses a contract from another workspace', function () {
    [$author, , $contract] = reusableContract();

    $elsewhere = workspaceWithMember($author, SystemRole::Admin);
    Feature::for($elsewhere)->activate(ContractsFeature::class);

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$elsewhere, $contract]), [
            'title' => 'Verkeerde workspace',
        ])
        ->assertNotFound();

    expect(Contract::query()->count())->toBe(1);
});

it('sends the boxes after the people once the new signers are named', function () {
    [$author, $workspace, $contract] = reusableContract();

    ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $contract->id,
        'label' => 'Handtekening huurder',
    ]);

    actingAs($author)
        ->post(route('chat.contracts.duplicate', [$workspace, $contract]), [
            'title' => 'Twee partijen',
        ])
        ->assertRedirect();

    $copy = Contract::query()->where('id', '!=', $contract->id)->sole();

    /*
     * The copy's boxes point at positions in a list that does not exist yet.
     * That is safe rather than sloppy: writing the new signers down is what
     * gives those numbers names again — see SaveContractSigners, which clamps
     * every field into the list it finds.
     */
    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $copy]), [
            'signers' => [
                ['name' => 'Cor Bakker', 'email' => 'cor@example.com'],
                ['name' => 'Dana Smit', 'email' => 'dana@example.com'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $signature = $copy->fields()->where('label', 'Handtekening huurder')->sole();

    expect($signature->signerIndex())->toBe(1);
});
