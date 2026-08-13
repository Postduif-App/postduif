<?php

use App\Actions\Contracts\NotifyContractAuthor;
use App\Actions\Contracts\RenderSignedContract;
use App\Actions\Contracts\SendSignedContract;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Mail\ContractSignedMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;

/**
 * Handing the finished document to the people who signed it.
 *
 * The bead this covers is the last thing the feature owes anybody outside the
 * building: a signer has no account, so without a mail the only copy of what
 * they agreed to lives behind somebody else's login. What has to hold is that
 * everybody who signed gets it, that nobody who refused does, and — the part
 * worth the column in the database — that a job which was retried does not post
 * the same document twice to the people it already reached.
 *
 * @return array{0: Contract, 1: User}
 */
function contractWithSignedCopy(): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->completed()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        'page_count' => 1,
    ]);

    /*
     * A faked PDF rather than a real one, and this suite is the place where that
     * is honest: what is being tested is who the document goes to, not what is
     * drawn on it. RenderSignedContractTest owns the composing, and it pays for
     * a genuine file to do it.
     */
    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $contract->update(['source_hash' => hash_file('sha256', $contract->source()->getPath())]);

    $contract->addMedia(UploadedFile::fake()->create('Huurovereenkomst 2026 (ondertekend).pdf', 30))
        ->usingFileName('Huurovereenkomst 2026 (ondertekend).pdf')
        ->toMediaCollection(Contract::SIGNED);

    return [$contract->fresh(['signers']), $author];
}

it('mails the finished document to everybody who signed, and to nobody else', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    $anna = ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'email' => 'anna@example.test',
    ]);
    $bram = ContractSigner::factory()->signed()->inPosition(1)->create([
        'contract_id' => $contract->id,
        'email' => 'bram@example.test',
    ]);

    /*
     * The two people who get nothing, and they are the point of the assertion.
     * Somebody who refused declined to enter into this — posting them the
     * evidence unasked reads as not having noticed — and somebody who never
     * answered has no copy of their own to be sent.
     */
    ContractSigner::factory()->declined()->inPosition(2)->create([
        'contract_id' => $contract->id,
        'email' => 'chris@example.test',
    ]);
    ContractSigner::factory()->inPosition(3)->create([
        'contract_id' => $contract->id,
        'email' => 'dana@example.test',
    ]);

    $sent = app(SendSignedContract::class)->handle($contract->fresh(['signers']));

    expect($sent)->toBe(2);

    Mail::assertSent(ContractSignedMail::class, 2);
    Mail::assertSent(ContractSignedMail::class, fn (ContractSignedMail $mail): bool => $mail->hasTo('anna@example.test'));
    Mail::assertSent(ContractSignedMail::class, fn (ContractSignedMail $mail): bool => $mail->hasTo('bram@example.test'));

    Mail::assertNotSent(ContractSignedMail::class, fn (ContractSignedMail $mail): bool => $mail->hasTo('chris@example.test')
        || $mail->hasTo('dana@example.test'));

    expect($anna->fresh()->copy_sent_at)->not->toBeNull();
    expect($bram->fresh()->copy_sent_at)->not->toBeNull();
});

it('puts the signed pdf in the mail rather than only a link to it', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    app(SendSignedContract::class)->handle($contract->fresh(['signers']));

    $signedCopy = $contract->fresh()->signedCopy();

    Mail::assertSent(ContractSignedMail::class, function (ContractSignedMail $mail) use ($signedCopy): bool {
        /*
         * Named as the document rather than as whatever the upload was called,
         * because two files in a downloads folder that cannot be told apart is
         * the failure this filename exists to avoid — see
         * RenderSignedContract::filename.
         */
        $mail->assertHasAttachment(
            Attachment::fromPath($signedCopy->getPath())
                ->as($signedCopy->file_name)
                ->withMime('application/pdf'),
        );

        return true;
    });
});

it('leaves alone anybody who already had their copy, so a retried job cannot send it twice', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    app(SendSignedContract::class)->handle($contract->fresh(['signers']));

    /*
     * The second call stands in for the second attempt of a job whose transport
     * gave out further down the list. The stamp is what makes it a no-op.
     */
    $again = app(SendSignedContract::class)->handle($contract->fresh(['signers']));

    expect($again)->toBe(0);
    Mail::assertSent(ContractSignedMail::class, 1);
});

it('sends nothing at all while the signed copy has not been composed', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    $contract->clearMediaCollection(Contract::SIGNED);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    expect(app(SendSignedContract::class)->handle($contract->fresh(['signers'])))->toBe(0);

    Mail::assertNothingSent();
});

it('posts the copies as part of finishing the contract, before the author is told', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'email' => 'anna@example.test',
    ]);

    /*
     * The composing is stubbed and the copy is already on the row, so this is a
     * test of the wiring rather than of the renderer — which is what lets it run
     * without Ghostscript. The notification is stubbed for the same reason.
     */
    $render = $this->mock(RenderSignedContract::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once());
    $notify = $this->mock(NotifyContractAuthor::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once());

    (new RenderSignedContractJob($contract->id))->handle(
        $render,
        $notify,
        app(SendSignedContract::class),
    );

    Mail::assertSent(ContractSignedMail::class, fn (ContractSignedMail $mail): bool => $mail->hasTo('anna@example.test'));
});

it('sends it round again when somebody asks from the detail screen', function () {
    Mail::fake();

    [$contract, $author] = contractWithSignedCopy();

    $anna = ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'email' => 'anna@example.test',
        'copy_sent_at' => now()->subDay(),
    ]);

    actingAs($author)
        ->post(route('chat.contracts.copy', [$contract->workspace, $contract]))
        ->assertRedirect();

    /*
     * Sent although the stamp says it already went out, and that is the whole
     * reason the button exists: the request is somebody saying they never got
     * it, and "onze administratie zegt van wel" is not an answer.
     */
    Mail::assertSent(ContractSignedMail::class, 1);

    expect($anna->fresh()->copy_sent_at->isToday())->toBeTrue();
});

it('has nothing to send from the screen while the copy is not ready', function () {
    Mail::fake();

    [$contract, $author] = contractWithSignedCopy();

    $contract->clearMediaCollection(Contract::SIGNED);

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    actingAs($author)
        ->post(route('chat.contracts.copy', [$contract->workspace, $contract]))
        ->assertNotFound();

    Mail::assertNothingSent();
});

it('refuses to mail the document round for somebody who may not have it themselves', function () {
    Mail::fake();

    [$contract] = contractWithSignedCopy();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    /*
     * A member of the workspace who is neither the author nor a manager. Behind
     * download() rather than a right of its own: mailing the document to the
     * people who signed it gives away nothing that fetching it does not, so the
     * two answers must not differ.
     */
    $outsider = User::factory()->create();
    joinWorkspace($contract->workspace, $outsider);

    actingAs($outsider)
        ->post(route('chat.contracts.copy', [$contract->workspace, $contract]))
        ->assertForbidden();

    Mail::assertNothingSent();
});

it('says so plainly when there is nobody who signed to send it to', function () {
    Mail::fake();

    [$contract, $author] = contractWithSignedCopy();

    ContractSigner::factory()->declined()->create(['contract_id' => $contract->id]);

    /*
     * A contract everybody refused is finished business with a document behind
     * it — the audit page is the record of the refusal — and the screen never
     * draws the button. Reaching the endpoint anyway must not claim mail went
     * out that did not.
     */
    actingAs($author)
        ->post(route('chat.contracts.copy', [$contract->workspace, $contract]))
        ->assertRedirect();

    Mail::assertNothingSent();
});

it('shows per signer when their copy went out', function () {
    [$contract, $author] = contractWithSignedCopy();

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'copy_sent_at' => now()->subHour(),
    ]);

    actingAs($author)
        ->get(route('chat.contracts.show', [$contract->workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.sendCopy', true)
            ->whereNot('contract.signers.0.copySentAt', null));
});
