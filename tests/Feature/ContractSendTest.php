<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Mail\ContractRequestMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * Naming the people and putting the contract in the post.
 *
 * The step where the feature stops being internal: from here on there are links
 * in strangers' inboxes, and every one of them is a credential.
 */

/** @return array{0: User, 1: Workspace, 2: Contract} */
function sendableContract(array $state = []): array
{
    Storage::fake('local');
    Mail::fake();

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    // A document has to be on it: a contract without one is a link to nothing.
    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    return [$author, $workspace, $contract];
}

it('gives every signer a link of their own and puts it in the post', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
                ['name' => 'Bram Jansen', 'email' => 'bram@example.com'],
            ],
            'valid_for_days' => 14,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Sent)
        ->and($contract->expires_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($contract->signers()->count())->toBe(2);

    $tokens = $contract->signers()->pluck('token');

    /*
     * Two tokens, not one shared one. A shared link could tell you that
     * somebody signed but not who, and who signed is the entire point.
     */
    expect($tokens->unique())->toHaveCount(2)
        ->and($tokens->first())->toHaveLength(64);

    // The order they were named becomes signing_order, which is what the boxes
    // already point at through signer_index.
    expect($contract->signers()->orderBy('signing_order')->pluck('name')->all())
        ->toBe(['Anna de Vries', 'Bram Jansen']);

    Mail::assertSent(ContractRequestMail::class, 2);
    Mail::assertSent(
        ContractRequestMail::class,
        fn (ContractRequestMail $mail): bool => $mail->hasTo('anna@example.com'),
    );
});

it('writes each signer their own link into the mail', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)->post(route('chat.contracts.send', [$workspace, $contract]), [
        'signers' => [['name' => 'Anna', 'email' => 'anna@example.com']],
    ]);

    $signer = $contract->signers()->sole();

    expect($signer->signUrl())->toBe(route('contracts.sign.show', $signer->token));

    Mail::assertSent(
        ContractRequestMail::class,
        fn (ContractRequestMail $mail): bool => $mail->signer->is($signer),
    );
});

it('refuses the same address twice', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Anna', 'email' => 'anna@example.com'],
                ['name' => 'Anna weer', 'email' => 'ANNA@example.com'],
            ],
        ])
        ->assertSessionHasErrors('signers');

    /*
     * Caught before the unique index does, so it reads as a mistake somebody
     * can correct rather than as a database error. Case-insensitively, because
     * an inbox is.
     */
    expect($contract->signers()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('replaces the list rather than adding to it', function () {
    [$author, $workspace, $contract] = sendableContract();

    ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        'email' => 'oud@example.com',
    ]);

    actingAs($author)->post(route('chat.contracts.send', [$workspace, $contract]), [
        'signers' => [['name' => 'Anna', 'email' => 'anna@example.com']],
    ]);

    /*
     * The list somebody edited is the list that gets invited, including the
     * address they removed. Safe because this only runs on a draft, so nobody
     * was holding a link yet.
     */
    expect($contract->signers()->pluck('email')->all())->toBe(['anna@example.com']);
});

it('sends nothing when the transaction could not happen', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna', 'email' => 'geen e-mailadres']],
        ])
        ->assertSessionHasErrors('signers.0.email');

    /*
     * The rule that carries the whole action: a mail is the one side effect
     * with no rollback, so nothing goes out until the rows are safely written.
     */
    Mail::assertNothingSent();
});

it('refuses to send a contract that has no document on it', function () {
    Storage::fake('local');
    Mail::fake();

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);
    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
    ]);

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna', 'email' => 'anna@example.com']],
        ])
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('refuses a colleague who is not in this workspace', function () {
    [$author, $workspace, $contract] = sendableContract();

    $outsider = User::factory()->create();

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [[
                'name' => $outsider->name,
                'email' => $outsider->email,
                'user_id' => $outsider->id,
            ]],
        ])
        ->assertSessionHasErrors('signers.0.user_id');
});

it('will not let a colleague without the right send one', function () {
    [, $workspace, $contract] = sendableContract();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    actingAs($member)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna', 'email' => 'anna@example.com']],
        ])
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('nudges only the people who have not answered', function () {
    [$author, $workspace, $contract] = sendableContract([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->addWeek(),
    ]);

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'email' => 'klaar@example.com',
    ]);
    ContractSigner::factory()->inPosition(1)->create([
        'contract_id' => $contract->id,
        'email' => 'wacht@example.com',
    ]);

    actingAs($author)
        ->post(route('chat.contracts.remind', [$workspace, $contract]))
        ->assertRedirect();

    /*
     * Being mailed about a contract you already signed reads as the sender not
     * having noticed.
     */
    Mail::assertSent(ContractRequestMail::class, 1);
    Mail::assertSent(
        ContractRequestMail::class,
        fn (ContractRequestMail $mail): bool => $mail->hasTo('wacht@example.com'),
    );
});

it('will not nudge the same person twice in a day', function () {
    [$author, $workspace, $contract] = sendableContract([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->addWeek(),
    ]);

    ContractSigner::factory()->create(['contract_id' => $contract->id]);

    actingAs($author)->post(route('chat.contracts.remind', [$workspace, $contract]));
    actingAs($author)->post(route('chat.contracts.remind', [$workspace, $contract]));

    // The button is not a way to sit on somebody's inbox.
    Mail::assertSent(ContractRequestMail::class, 1);
});

it('nudges again once the day is up', function () {
    [$author, $workspace, $contract] = sendableContract([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->addMonth(),
    ]);

    ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        'reminded_at' => now()->subDays(2),
    ]);

    actingAs($author)->post(route('chat.contracts.remind', [$workspace, $contract]));

    Mail::assertSent(ContractRequestMail::class, 1);
});

it('says so plainly when there is nobody left to nudge', function () {
    [$author, $workspace, $contract] = sendableContract([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->addWeek(),
    ]);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->post(route('chat.contracts.remind', [$workspace, $contract]))
        ->assertRedirect();

    // Zero is an ordinary answer, not a failure — and the page must not claim a
    // mail went out that did not.
    Mail::assertNothingSent();
});

it('will not nudge anybody about a contract that has run out', function () {
    [$author, $workspace, $contract] = sendableContract([
        'status' => ContractStatus::Sent,
        'expires_at' => now()->subDay(),
    ]);

    ContractSigner::factory()->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->post(route('chat.contracts.remind', [$workspace, $contract]))
        ->assertForbidden();

    Mail::assertNothingSent();
});
