<?php

use App\Actions\Contracts\PruneContracts;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/**
 * The nightly clearing up: closing what has run out, and removing what came to
 * nothing.
 *
 * The test that matters most in here is the one that says a completed contract
 * is never touched. Everything else is housekeeping; that one is the difference
 * between a filing cabinet and a shredder on a timer.
 */
it('marks a contract expired once its deadline has passed', function () {
    $overdue = Contract::factory()->overdue()->create();
    $running = Contract::factory()->sent()->create();

    (new PruneContracts)->handle();

    expect($overdue->fresh()->status)->toBe(ContractStatus::Expired)
        ->and($running->fresh()->status)->toBe(ContractStatus::Sent);
});

it('leaves a contract with no deadline alone forever', function () {
    $open = Contract::factory()->sent()->create(['expires_at' => null]);

    (new PruneContracts)->handle();

    /*
     * Null means "no deadline", and in SQL a null compares to nothing — the
     * kind of thing that quietly works until somebody writes the query the
     * other way round.
     */
    expect($open->fresh()->status)->toBe(ContractStatus::Sent);
});

it('never touches a contract that was signed', function () {
    $completed = Contract::factory()->completed()->create([
        'expires_at' => now()->subYears(2),
        'created_at' => now()->subYears(2),
    ]);

    (new PruneContracts)->handle();

    /*
     * Two years past its deadline and still here, because it is the piece of
     * evidence the whole feature exists to produce. Somebody put their name
     * under something and holds a copy that says so.
     */
    expect($completed->fresh())->not->toBeNull()
        ->and($completed->fresh()->status)->toBe(ContractStatus::Completed);
});

it('clears away a withdrawn contract once the grace period is up', function () {
    config()->set('contracts.grace_days', 30);

    $old = Contract::factory()->cancelled(now()->subMonths(2))->create();
    $recent = Contract::factory()->cancelled(now()->subDays(3))->create();

    (new PruneContracts)->handle();

    expect(Contract::find($old->id))->toBeNull()
        ->and(Contract::find($recent->id))->not->toBeNull();
});

it('clears away an expired contract counting from its deadline', function () {
    config()->set('contracts.grace_days', 30);

    $old = Contract::factory()->expired(now()->subMonths(2))->create();
    $recent = Contract::factory()->expired(now()->subDays(3))->create();

    (new PruneContracts)->handle();

    expect(Contract::find($old->id))->toBeNull()
        ->and(Contract::find($recent->id))->not->toBeNull();
});

it('takes the document and the signatures off the disk with it', function () {
    Storage::fake('local');

    config()->set('contracts.grace_days', 30);

    $contract = Contract::factory()->cancelled(now()->subMonths(2))->create();

    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $signer = ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    $signer->addMedia(UploadedFile::fake()->image('handtekening.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    expect(Storage::disk('local')->allFiles())->toHaveCount(2);

    (new PruneContracts)->handle();

    /*
     * Both halves, and the second one is the trap: contract_signers cascades in
     * the database, and a database cascade fires no Eloquent events — so
     * without the deleting hook on Contract the signature would still be sitting
     * on the disk with an orphaned media row pointing at it.
     */
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('deletes a contract one at a time so the media library sees it go', function () {
    Storage::fake('local');

    config()->set('contracts.grace_days', 30);

    Contract::factory()->count(3)->cancelled(now()->subMonths(2))->create()
        ->each(fn (Contract $contract) => $contract
            ->addMedia(UploadedFile::fake()->create('contract.pdf', 10))
            ->toMediaCollection(Contract::SOURCE));

    ['removed' => $removed] = (new PruneContracts)->handle();

    expect($removed)->toBe(3)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('reports both halves of the job on the command line', function () {
    Contract::factory()->overdue()->create();

    artisan('contracts:prune')
        ->expectsOutputToContain('1')
        ->assertSuccessful();
});

it('says so plainly when there was nothing to do', function () {
    artisan('contracts:prune')
        ->expectsOutputToContain(__('console.nothing_to_prune'))
        ->assertSuccessful();
});
