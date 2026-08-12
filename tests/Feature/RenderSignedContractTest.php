<?php

use App\Actions\Contracts\NotifyContractAuthor;
use App\Actions\Contracts\RenderSignedContract;
use App\Actions\Contracts\SigningRefused;
use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use setasign\Fpdi\Tcpdf\Fpdi;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Composing the document people actually get.
 *
 * The overlay itself cannot really be asserted on — what is being produced is a
 * picture, and "does the signature sit on the line" is a question for eyes. So
 * what is checked here is everything around it that can go wrong silently: that
 * the right number of pages come out, that the audit page is on the back, that
 * a document which has changed underneath is refused, and above all that a
 * failure never costs anybody their signature.
 */
beforeEach(function () {
    $binary = (string) config('contracts.ghostscript');

    if ($binary === '' || (! is_executable($binary) && shell_exec('command -v '.escapeshellarg($binary)) === null)) {
        $this->markTestSkipped('Ghostscript is not installed; a real PDF cannot be produced.');
    }
});

/**
 * A signed contract with a genuine PDF behind it.
 *
 * A faked file would not survive setSourceFile, which is the first thing the
 * renderer does — so this suite builds a real one, normalised the way an upload
 * would be.
 *
 * @return array{0: Contract, 1: ContractSigner, 2: User}
 */
function signedContract(int $pages = 2): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        'status' => ContractStatus::Completed,
        'completed_at' => now(),
        'page_count' => $pages,
    ]);

    $contract->addMedia(realPdf($pages))->toMediaCollection(Contract::SOURCE);

    $contract->update(['source_hash' => hash_file('sha256', $contract->source()->getPath())]);

    $signer = ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
        'signature_method' => SignatureMethod::Drawn,
        'signed_document_hash' => $contract->source_hash,
    ]);

    return [$contract->fresh(['fields', 'signers']), $signer, $author];
}

/** How many pages a produced PDF turned out to have. */
function pagesIn(string $path): int
{
    return (new Fpdi)->setSourceFile($path);
}

it('puts every page of the original back, with the audit trail behind it', function () {
    [$contract] = signedContract(pages: 3);

    app(RenderSignedContract::class)->handle($contract);

    $signed = $contract->fresh()->signedCopy();

    expect($signed)->not->toBeNull();

    // Three pages of contract and one of audit trail.
    expect(pagesIn($signed->getPath()))->toBe(4);
});

it('adds the audit trail even when nobody signed at all', function () {
    [$contract, $signer] = signedContract(pages: 1);

    $signer->forceFill([
        'signed_at' => null,
        'signed_document_hash' => null,
        'declined_at' => now(),
        'decline_reason' => 'Niet akkoord met artikel 4.',
    ])->save();

    app(RenderSignedContract::class)->handle($contract->fresh(['signers']));

    /*
     * A contract everybody refused is finished business too, and the record of
     * who was asked and what they said is exactly as much of a document as a
     * set of signatures would be.
     */
    expect(pagesIn($contract->fresh()->signedCopy()->getPath()))->toBe(2);
});

it('names the file after the contract rather than after the upload', function () {
    [$contract] = signedContract();

    app(RenderSignedContract::class)->handle($contract);

    /*
     * Two files in a downloads folder that can be told apart. The spaces come
     * out as dashes because the media library sanitises what it is given — its
     * call, and the right one: this name ends up in a Content-Disposition
     * header and in a filesystem.
     */
    expect($contract->fresh()->signedCopy()->file_name)
        ->toBe('Huurovereenkomst-2026-(ondertekend).pdf');
});

it('draws what people typed and what they drew', function () {
    [$contract, $signer] = signedContract(pages: 1);

    $text = ContractField::factory()->create([
        'contract_id' => $contract->id,
        'label' => 'Naam huurder',
    ]);
    $signature = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
    ]);

    ContractFieldValue::factory()->create([
        'contract_field_id' => $text->id,
        'contract_signer_id' => $signer->id,
        'value' => 'Anna de Vries',
    ]);
    ContractFieldValue::factory()->drawn()->create([
        'contract_field_id' => $signature->id,
        'contract_signer_id' => $signer->id,
    ]);

    /*
     * Held in a variable rather than chained: the fake file is a temporary one
     * whose handle is what keeps it on disk, and letting it fall out of scope
     * mid-expression deletes it before the media library gets there.
     */
    $drawing = UploadedFile::fake()->image('signature.png', 300, 100);

    $signer->addMedia($drawing)->toMediaCollection(ContractSigner::SIGNATURE);

    app(RenderSignedContract::class)->handle($contract->fresh(['fields', 'signers']));

    $signed = $contract->fresh()->signedCopy();

    /*
     * The overlay cannot be asserted on directly — it is a picture. What can be
     * said is that the document grew: the typed name went in as real text and
     * the signature as an embedded image, and neither is free.
     */
    expect($signed->size)->toBeGreaterThan($contract->source()->size);
});

it('refuses to compose a copy of a document that has changed', function () {
    [$contract] = signedContract();

    file_put_contents($contract->source()->getPath(), 'iets heel anders');

    /*
     * Checked again although the signing already did: that was at the moment
     * of signing, this runs later on a queue, possibly after a retry.
     * Producing a "signed copy" of a document nobody signed is the one failure
     * this feature exists to make impossible.
     */
    expect(fn () => app(RenderSignedContract::class)->handle($contract))
        ->toThrow(SigningRefused::class);

    expect($contract->fresh()->signedCopy())->toBeNull();
});

it('leaves the contract signed when composing it goes wrong', function () {
    [$contract] = signedContract();

    (new RenderSignedContractJob($contract->id))->failed(new RuntimeException('kapot'));

    $contract->refresh();

    /*
     * The signature has been given and must not be lost because a rendering
     * step stumbled. The failure sits beside the status, never in it.
     */
    expect($contract->status)->toBe(ContractStatus::Completed)
        ->and($contract->completed_at)->not->toBeNull()
        ->and($contract->render_failed_at)->not->toBeNull()
        ->and($contract->signedCopyState())->toBe('failed');
});

it('clears the failure once a later attempt works', function () {
    [$contract] = signedContract();

    $contract->forceFill(['render_failed_at' => now()->subHour()])->save();

    (new RenderSignedContractJob($contract->id))->handle(
        app(RenderSignedContract::class),
        app(NotifyContractAuthor::class),
    );

    // An overview that keeps flagging a contract whose PDF is sitting right
    // there is an overview people stop reading.
    expect($contract->fresh())
        ->render_failed_at->toBeNull()
        ->signedCopyState()->toBe('ready');
});

it('tells the three states apart', function () {
    [$contract] = signedContract();

    expect($contract->signedCopyState())->toBe('pending');

    app(RenderSignedContract::class)->handle($contract);

    expect($contract->fresh()->signedCopyState())->toBe('ready');

    // A contract still out with the signers has nothing to make yet.
    expect(Contract::factory()->sent()->create()->signedCopyState())->toBe('none');
});

it('queues the work as soon as the last person answers', function () {
    Queue::fake();

    [$contract, $signer] = signedContract(pages: 1);

    $signer->forceFill(['signed_at' => null, 'signed_document_hash' => null])->save();
    $contract->update(['status' => ContractStatus::Sent, 'completed_at' => null]);

    post(route('contracts.sign.complete', $signer->token))->assertSessionHasNoErrors();

    Queue::assertPushed(
        RenderSignedContractJob::class,
        fn (RenderSignedContractJob $job): bool => $job->contractId === $contract->id,
    );
});

it('queues nothing while somebody has still to answer', function () {
    Queue::fake();

    [$contract, $signer] = signedContract(pages: 1);

    $signer->forceFill(['signed_at' => null, 'signed_document_hash' => null])->save();
    $contract->update(['status' => ContractStatus::Sent, 'completed_at' => null]);

    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $signer->token));

    Queue::assertNotPushed(RenderSignedContractJob::class);
});

it('hands the finished copy to the author as a download', function () {
    [$contract, , $author] = signedContract();

    app(RenderSignedContract::class)->handle($contract);

    actingAs($author)
        ->get(route('chat.contracts.download', [$contract->workspace, $contract]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="Huurovereenkomst-2026-(ondertekend).pdf"');
});

it('keeps the finished copy from a colleague it was not shown to', function () {
    [$contract] = signedContract();

    app(RenderSignedContract::class)->handle($contract);

    $colleague = User::factory()->create();
    joinWorkspace($contract->workspace, $colleague, SystemRole::Member);

    actingAs($colleague)
        ->get(route('chat.contracts.download', [$contract->workspace, $contract]))
        ->assertForbidden();
});

it('hands the signer their own copy behind their token', function () {
    [$contract, $signer] = signedContract();

    app(RenderSignedContract::class)->handle($contract);

    /*
     * They have no account for a policy to judge, and every claim to the
     * document they put their name under. A signature nobody can produce the
     * document for is not evidence of anything.
     */
    get(route('contracts.sign.copy', $signer->token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('has nothing to hand over while it is still being made', function () {
    [$contract, $signer, $author] = signedContract();

    actingAs($author)
        ->get(route('chat.contracts.download', [$contract->workspace, $contract]))
        ->assertNotFound();

    get(route('contracts.sign.copy', $signer->token))->assertNotFound();
});
