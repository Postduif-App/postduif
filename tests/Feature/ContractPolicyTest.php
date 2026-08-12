<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who may do what with a contract from the inside.
 *
 * The two questions worth a suite of their own are the ones that come apart:
 * changing a contract stops the moment somebody has signed, while stopping one
 * carries on for exactly as long as it is still out. Everything else here is
 * guarding the line that a contract is narrower than a form — being in the
 * workspace buys nothing.
 */

/**
 * An author, a colleague, an admin and a contract between them.
 *
 * @return array{author: User, colleague: User, admin: User, contract: Contract}
 */
function contractCast(array $state = []): array
{
    $workspace = Workspace::factory()->create();

    $author = User::factory()->create();
    $colleague = User::factory()->create();
    $admin = User::factory()->create();

    joinWorkspace($workspace, $author, SystemRole::Member);
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    return compact('author', 'colleague', 'admin', 'contract');
}

it('lets the author and a workspace manager look, and nobody else', function () {
    ['author' => $author, 'colleague' => $colleague, 'admin' => $admin, 'contract' => $contract] = contractCast();

    expect($author->can('view', $contract))->toBeTrue()
        ->and($admin->can('view', $contract))->toBeTrue()
        ->and($colleague->can('view', $contract))->toBeFalse();

    // Somebody from another workspace entirely does not even have a foothold.
    expect(User::factory()->create()->can('view', $contract))->toBeFalse();
});

it('allows the boxes to be moved while nobody has signed', function () {
    ['author' => $author, 'contract' => $contract] = contractCast();

    expect($author->can('update', $contract))->toBeTrue();

    $contract->update(['status' => ContractStatus::Sent]);

    // Still editable once it is out but before anybody has been round: the
    // author noticing a typo the minute after sending is an ordinary Tuesday.
    expect($author->fresh()->can('update', $contract->fresh()))->toBeTrue();
});

it('freezes the document the moment somebody has signed', function () {
    ['author' => $author, 'admin' => $admin, 'contract' => $contract] = contractCast([
        'status' => ContractStatus::Sent,
    ]);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    /*
     * The rule the whole feature stands on: moving a signature box after
     * somebody has signed would change what they agreed to between reading it
     * and signing it. Not even the workspace's owner gets round that.
     */
    expect($author->can('update', $contract->fresh()))->toBeFalse()
        ->and($admin->can('update', $contract->fresh()))->toBeFalse();
});

it('still allows a half-signed contract to be stopped', function () {
    ['author' => $author, 'contract' => $contract] = contractCast([
        'status' => ContractStatus::Sent,
    ]);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    /*
     * Stopping is not changing, and this is exactly the contract somebody most
     * urgently needs to be able to stop — one of three has signed and the wrong
     * document is out there.
     */
    expect($author->can('cancel', $contract->fresh()))->toBeTrue()
        ->and($author->can('update', $contract->fresh()))->toBeFalse();
});

it('refuses to withdraw or delete a contract that is finished', function () {
    ['author' => $author, 'admin' => $admin, 'contract' => $contract] = contractCast();

    $contract->update([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
    ]);

    /*
     * The one thing here somebody else relies on: whoever signed holds a copy
     * and may assume this one still exists. Withdrawing it after the fact would
     * be rewriting what happened.
     */
    expect($author->can('cancel', $contract))->toBeFalse()
        ->and($author->can('delete', $contract))->toBeFalse()
        ->and($admin->can('delete', $contract))->toBeFalse();

    // Reading it, though, stays open — that is what it is for.
    expect($author->can('view', $contract))->toBeTrue()
        ->and($author->can('download', $contract))->toBeTrue();
});

it('will not remind anybody about a contract that has run out', function () {
    ['author' => $author, 'contract' => $contract] = contractCast([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->subDay(),
    ]);

    /*
     * Asked of the date rather than of the status column, which still says
     * Sent until the nightly command catches up. A deadline that passed an hour
     * ago has passed.
     */
    expect($author->can('remind', $contract))->toBeFalse();
});

it('asks for the right to send contracts before letting anybody start one', function () {
    $workspace = Workspace::factory()->create();

    $admin = User::factory()->create();
    $member = User::factory()->create();

    joinWorkspace($workspace, $admin, SystemRole::Admin);
    joinWorkspace($workspace, $member, SystemRole::Member);

    expect($workspace->allows($admin, WorkspaceAbility::SendContracts))->toBeTrue()
        ->and($admin->can('create', [Contract::class, $workspace]))->toBeTrue()
        ->and($member->can('create', [Contract::class, $workspace]))->toBeFalse();
});
