<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * The list and the buttons beside it.
 *
 * The one to read carefully is withdrawing. The bead calls it "de tokens dood
 * maken", and the obvious implementation — rotating them — is wrong: the links
 * have to keep resolving so the person holding one is told what happened.
 */

/** @return array{0: User, 1: Workspace, 2: Contract} */
function managedContract(array $state = []): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        ...$state,
    ]);

    return [$author, $workspace, $contract];
}

it('lists the contracts of this workspace', function () {
    [$author, $workspace, $contract] = managedContract();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->get(route('chat.contracts.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contracts')
            ->has('contracts', 1)
            ->where('contracts.0.title', 'Huurovereenkomst 2026')
            ->where('contracts.0.signedCount', 1)
            ->where('contracts.0.signerCount', 2));
});

it('shows a member only their own', function () {
    [, $workspace, $theirs] = managedContract();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);
    setAbility($workspace, WorkspaceAbility::SendContracts, true, SystemRole::Member);

    $mine = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'title' => 'Van mij',
    ]);

    /*
     * The same line the policy draws, in SQL. Fetching everything and sieving
     * it afterwards would leave somebody paging through a list that is mostly
     * gaps.
     */
    actingAs($member)
        ->get(route('chat.contracts.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('contracts', 1)
            ->where('contracts.0.id', $mine->id));

    expect($theirs->created_by)->not->toBe($member->id);
});

it('shows a workspace manager everything', function () {
    [, $workspace] = managedContract();

    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    // Not to police what colleagues send, but so a contract sent to the wrong
    // address has somebody who can stop it.
    actingAs($admin)
        ->get(route('chat.contracts.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('contracts', 1));
});

it('withdraws a contract without breaking its links', function () {
    [$author, $workspace, $contract] = managedContract();

    $signer = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    $token = $signer->token;

    actingAs($author)
        ->post(route('chat.contracts.cancel', [$workspace, $contract]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contract->fresh())
        ->status->toBe(ContractStatus::Cancelled)
        ->cancelled_at->not->toBeNull();

    /*
     * The token is untouched, and that is the point. Rotating it would give
     * whoever holds the link a 404 to interpret; leaving it means they are told
     * what happened.
     */
    expect($signer->fresh()->token)->toBe($token);

    get(route('contracts.sign.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'cancelled'));
});

it('still lets a half-signed contract be stopped', function () {
    [$author, $workspace, $contract] = managedContract();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    // Exactly the contract somebody most urgently needs to be able to stop.
    actingAs($author)
        ->post(route('chat.contracts.cancel', [$workspace, $contract]))
        ->assertSessionHasNoErrors();

    expect($contract->fresh()->status)->toBe(ContractStatus::Cancelled);
});

it('refuses to withdraw a contract that is already finished', function () {
    [$author, $workspace, $contract] = managedContract([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
    ]);

    // Evidence. Withdrawing it after the fact would be rewriting what happened.
    actingAs($author)
        ->post(route('chat.contracts.cancel', [$workspace, $contract]))
        ->assertForbidden();

    expect($contract->fresh()->status)->toBe(ContractStatus::Completed);
});

it('refuses to withdraw the same contract twice', function () {
    [$author, $workspace, $contract] = managedContract([
        'status' => ContractStatus::Cancelled,
        'cancelled_at' => now()->subHour(),
    ]);

    actingAs($author)
        ->post(route('chat.contracts.cancel', [$workspace, $contract]))
        ->assertForbidden();
});

it('puts the contract in a channel as an ordinary message', function () {
    [$author, $workspace, $contract] = managedContract();

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    actingAs($author)
        ->post(route('chat.contracts.post', [$workspace, $contract]), [
            'channel_id' => $channel->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $message = Message::query()->where('channel_id', $channel->id)->sole();

    /*
     * A link and nothing else. What makes it readable is the card
     * PresentMessage draws; take the card away and a member still has a link
     * that works, which is what makes this cheap to do more than once.
     */
    expect($message->body)
        ->toContain('Huurovereenkomst 2026')
        ->toContain(route('chat.contracts.show', [$workspace->slug, $contract->id]))
        ->and($message->user_id)->toBe($author->id);
});

it('will not post into a channel from another workspace', function () {
    [$author, $workspace, $contract] = managedContract();

    $elsewhere = Channel::factory()->create();

    actingAs($author)
        ->post(route('chat.contracts.post', [$workspace, $contract]), [
            'channel_id' => $elsewhere->id,
        ])
        ->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('queues another attempt at the signed copy', function () {
    Queue::fake();

    [$author, $workspace, $contract] = managedContract([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
        'render_failed_at' => now()->subHour(),
    ]);

    expect($contract->signedCopyState())->toBe('failed');

    actingAs($author)
        ->post(route('chat.contracts.retry', [$workspace, $contract]))
        ->assertRedirect();

    /*
     * Cleared before the job runs rather than after it succeeds, so the screen
     * stops saying "misgegaan" the moment somebody has asked for another go. If
     * it fails again, failed() puts it back.
     */
    expect($contract->fresh()->render_failed_at)->toBeNull();

    Queue::assertPushed(
        RenderSignedContractJob::class,
        fn (RenderSignedContractJob $job): bool => $job->contractId === $contract->id,
    );
});

it('has nothing to retry when nothing went wrong', function () {
    Queue::fake();

    [$author, $workspace, $contract] = managedContract([
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
    ]);

    actingAs($author)
        ->post(route('chat.contracts.retry', [$workspace, $contract]))
        ->assertNotFound();

    Queue::assertNotPushed(RenderSignedContractJob::class);
});

it('offers the send panel on a draft and not on a contract that is out', function () {
    [$author, $workspace, $draft] = managedContract([
        'status' => ContractStatus::Draft,
    ]);

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $draft]))
        ->assertInertia(fn ($page) => $page
            ->where('can.send', true)
            // The colleagues who can be named without typing an address.
            ->has('members'));

    $sent = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
    ]);

    /*
     * Sending is the step that hands the document to the outside world, and
     * there is no second chance at it: the way to change a contract that is out
     * is to withdraw it and start again.
     */
    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $sent]))
        ->assertInertia(fn ($page) => $page->where('can.send', false));
});

it('keeps the whole list away from somebody who may not send contracts', function () {
    [, $workspace] = managedContract();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    actingAs($member)
        ->get(route('chat.contracts.index', $workspace))
        ->assertForbidden();
});

it('does not exist for a workspace that has not switched contracts on', function () {
    [$author, $workspace] = managedContract();

    Feature::for($workspace)->deactivate(ContractsFeature::class);

    actingAs($author)
        ->get(route('chat.contracts.index', $workspace))
        ->assertNotFound();
});
