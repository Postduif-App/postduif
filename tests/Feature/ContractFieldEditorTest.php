<?php

use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The server half of the field editor: what it hands the page, and what it
 * accepts back.
 *
 * The geometry itself is tested where it lives — resources/js/lib, in vitest,
 * without a browser. What is asked here is the part only the server can answer:
 * who may lay out a contract, which boxes it will store, and the one rule the
 * whole feature rests on — that a signed contract stops being editable.
 */

/** @return array{0: User, 1: Workspace, 2: Contract} */
function contractBeingLaidOut(array $state = []): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'page_count' => 3,
        ...$state,
    ]);

    return [$author, $workspace, $contract];
}

/** The smallest box the endpoint accepts. */
function contractFieldPayload(array $overrides = []): array
{
    return [
        'page' => 1,
        'x' => 0.2,
        'y' => 0.3,
        'width' => 0.25,
        'height' => 0.08,
        'type' => ContractFieldType::Signature->value,
        'label' => 'Handtekening huurder',
        'is_required' => true,
        ...$overrides,
    ];
}

it('opens the editor on the contract with its document behind it', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
        'label' => 'Handtekening huurder',
    ]);

    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contract-edit')
            ->where('contract.pageCount', 3)
            ->has('contract.fields', 1)
            ->where('contract.fields.0.label', 'Handtekening huurder')

            /*
             * A route rather than a path to the file. The document is on the
             * private disk and this is the only way to it — a page that handed
             * out anything else would be handing out a way round the policy.
             */
            ->where('contract.sourceUrl', route('chat.contracts.source', [$workspace, $contract]))

            // The catalogue comes from the enum, sizes and all, so the editor
            // never carries a second list that can drift from it.
            ->has('fieldTypes', count(ContractFieldType::cases()))
        );
});

it('hands the coordinates over as numbers, not as strings', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    ContractField::factory()->create([
        'contract_id' => $contract->id,
        'x' => 0.125,
    ]);

    /*
     * A decimal column comes back from PDO as a string, and the editor does
     * arithmetic on these the moment somebody drags one. '0.125' + 0.1 is
     * '0.1250.1' in JavaScript, which puts the box somewhere no page has.
     */
    $props = actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->viewData('page')['props'];

    expect($props['contract']['fields'][0]['x'])->toBeFloat()->toBe(0.125);
});

it('stores the boxes that were drawn', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [
                contractFieldPayload(),
                contractFieldPayload([
                    'page' => 2,
                    'type' => ContractFieldType::Date->value,
                    'label' => 'Datum',
                    'is_required' => false,
                ]),
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contract->fields()->count())->toBe(2);

    $signature = $contract->fields()->where('type', ContractFieldType::Signature)->sole();

    expect((float) $signature->x)->toBe(0.2)
        ->and((float) $signature->height)->toBe(0.08)
        ->and($signature->page)->toBe(1)
        ->and($signature->is_required)->toBeTrue()
        // The order of the array is the order of the boxes.
        ->and($signature->position)->toBe(0);
});

it('moves an existing box rather than laying a second one beside it', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    $field = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);

    actingAs($author)->put(route('chat.contracts.fields', [$workspace, $contract]), [
        'fields' => [contractFieldPayload(['id' => $field->id, 'y' => 0.75])],
    ]);

    expect($contract->fields()->count())->toBe(1)
        ->and((float) $field->fresh()->y)->toBe(0.75);
});

it('deletes a box that is no longer in the payload', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    ContractField::factory()->count(3)->create(['contract_id' => $contract->id]);

    actingAs($author)->put(route('chat.contracts.fields', [$workspace, $contract]), [
        'fields' => [],
    ]);

    // A sync, not a merge: the editor is one page somebody drags things about
    // on, and what is not on it any more is gone.
    expect($contract->fields()->count())->toBe(0);
});

it('refuses a box on a page the document does not have', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut(['page_count' => 3]);

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [contractFieldPayload(['page' => 9])],
        ])
        ->assertSessionHasErrors('fields.0.page');

    /*
     * Not a nicety: a box on page nine of a three-page contract is a field
     * nobody can ever fill in, because there is no page to draw it on.
     */
    expect($contract->fields()->count())->toBe(0);
});

it('refuses a coordinate that is not a fraction of the page', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [contractFieldPayload(['x' => 1.4])],
        ])
        ->assertSessionHasErrors('fields.0.x');
});

it('refuses a box addressed to a signer who does not exist', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    ContractSigner::factory()->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [contractFieldPayload(['signer_index' => 3])],
        ])
        ->assertSessionHasErrors('fields.0.signer_index');
});

it('freezes the layout once somebody has signed', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut([
        'status' => ContractStatus::Sent,
    ]);

    $field = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
        'y' => 0.3,
    ]);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    /*
     * The rule the whole feature rests on. Moving a signature box after
     * somebody signed would change what they agreed to between reading it and
     * signing it — so the editor is not merely disabled in the browser, the
     * endpoint refuses.
     */
    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [contractFieldPayload(['id' => $field->id, 'y' => 0.9])],
        ])
        ->assertForbidden();

    expect((float) $field->fresh()->y)->toBe(0.3);

    // And the editor itself is not reachable either, rather than opening onto
    // a document that quietly refuses every save.
    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->assertForbidden();
});

it('keeps the editor away from a colleague the contract was not shown to', function () {
    [, $workspace, $contract] = contractBeingLaidOut();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    actingAs($colleague)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->assertForbidden();

    actingAs($colleague)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [contractFieldPayload()],
        ])
        ->assertForbidden();
});

it('does not exist for a workspace that has not switched contracts on', function () {
    [$author, $workspace, $contract] = contractBeingLaidOut();

    Feature::for($workspace)->deactivate(ContractsFeature::class);

    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->assertNotFound();
});

it('will not lay boxes over somebody else\'s contract through this workspace', function () {
    [$author, $workspace] = contractBeingLaidOut();

    $elsewhere = Contract::factory()->create(['page_count' => 3]);

    /*
     * The id resolves perfectly well on its own; what stops it is the check
     * that the contract belongs to the workspace in the path.
     */
    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $elsewhere]))
        ->assertNotFound();
});
