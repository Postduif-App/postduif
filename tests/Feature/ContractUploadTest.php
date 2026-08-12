<?php

use App\Actions\Contracts\NormalisePdf;
use App\Actions\Contracts\PdfRefused;
use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * Getting a PDF in, and getting it back out again.
 *
 * Most of this suite is about refusals rather than about the happy path, and
 * that is the right balance for this particular door: the file that comes
 * through it is mailed to people outside the workspace with "hier moet je
 * tekenen" beside it, which is about the most trusting frame of mind anybody
 * opens a document in.
 */

/**
 * A workspace that has switched contracts on, and somebody in it who may send
 * them.
 *
 * The feature is activated by hand rather than left to the factory, and that is
 * the point of it: contracts are off until a workspace says otherwise, so every
 * test that wants one has to say so too.
 *
 * @return array{0: User, 1: Workspace}
 */
function contractSenderInWorkspace(SystemRole $role = SystemRole::Admin): array
{
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(ContractsFeature::class);

    return [$user, $workspace];
}

/**
 * Nothing in this suite means anything without the rewriter.
 *
 * Skipped rather than failed when it is missing, because "Ghostscript staat
 * niet op deze machine" is a fact about the machine and not about the code —
 * and a red suite that says nothing about the change somebody just made is a
 * suite people learn to ignore.
 */
beforeEach(function () {
    $binary = (string) config('contracts.ghostscript');

    $found = $binary !== '' && (is_executable($binary) || shell_exec('command -v '.escapeshellarg($binary)) !== null);

    if (! $found) {
        $this->markTestSkipped('Ghostscript is not installed; the contract upload cannot be exercised.');
    }
});

it('opens a draft on the uploaded document', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Huurovereenkomst 2026',
            'message' => 'Graag voor vrijdag tekenen.',
            'valid_for_days' => 14,
            'file' => uploadedPdf(pages: 3),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract = Contract::sole();

    expect($contract)
        ->workspace_id->toBe($workspace->id)
        ->created_by->toBe($user->id)
        ->title->toBe('Huurovereenkomst 2026')
        ->status->toBe(ContractStatus::Draft)
        ->page_count->toBe(3);

    expect($contract->expires_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($contract->hasSource())->toBeTrue();
});

it('keeps the name the author uploaded, although the bytes are a rewrite', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => uploadedPdf(),
    ]);

    // The two halves that are deliberately not the same thing: what a signer
    // will recognise the document by, and what actually got stored.
    expect(Contract::sole()->source()->file_name)->toBe('huurovereenkomst.pdf');
});

it('records a hash of what was stored, not of what was uploaded', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    $file = uploadedPdf();
    $uploadHash = hash_file('sha256', $file->getRealPath());

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => $file,
    ]);

    $contract = Contract::sole();

    /*
     * The distinction the whole audit trail rests on. The stored file is a
     * Ghostscript rewrite of the upload, so a hash of the upload would prove
     * something about a document nobody ever saw or signed.
     */
    expect($contract->source_hash)
        ->not->toBe($uploadHash)
        ->toBe(hash_file('sha256', $contract->source()->getPath()));
});

it('strips embedded javascript out of the stored document', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    $file = uploadedPdf(javascript: 'app.alert("hallo");');

    expect(file_get_contents($file->getRealPath()))->toContain('/JavaScript');

    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Met script erin',
            'file' => $file,
        ])
        ->assertSessionHasNoErrors();

    /*
     * Accepted rather than refused, and that is the design: the rewrite is what
     * removes the script, so the author gets a working contract instead of a
     * refusal they can do nothing about. The refusal is the backstop for when
     * something survives — see the unit test on NormalisePdf below.
     */
    expect(file_get_contents(Contract::sole()->source()->getPath()))
        ->not->toContain('/JavaScript')
        ->not->toContain('/JS');
});

it('refuses a file that is not a pdf', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Geen pdf',
            'file' => UploadedFile::fake()->create('offerte.docx', 20),
        ])
        ->assertSessionHasErrors('file');

    expect(Contract::count())->toBe(0);
});

it('refuses a document with more pages than we will render', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    config()->set('contracts.max_pages', 2);

    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Veel te lang',
            'file' => uploadedPdf(pages: 4),
        ])
        ->assertSessionHasErrors('file');

    expect(Contract::count())->toBe(0);
});

it('writes nothing when the document is refused', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    config()->set('contracts.max_pages', 1);

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Veel te lang',
        'file' => uploadedPdf(pages: 4),
    ]);

    // Neither half of the pair — the row and the bytes are one thing, and a
    // refusal has to leave neither behind.
    expect(Contract::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('refuses a rewritten document that still carries something executable', function () {
    /*
     * The backstop, exercised by moving the goalposts: Ghostscript's own
     * output is checked against a list that now forbids something every PDF
     * has. What is being tested is that the check is actually applied to the
     * output — not that Ghostscript fails, which it does not.
     */
    $normalise = new class extends NormalisePdf
    {
        protected function forbidden(): array
        {
            return ['%PDF'];
        }
    };

    expect(fn () => $normalise->handle(realPdf()))->toThrow(PdfRefused::class);
});

it('does not exist for a workspace that has not switched contracts on', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    // Deliberately not activated.
    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Huurovereenkomst',
            'file' => uploadedPdf(),
        ])
        ->assertNotFound();
});

it('refuses somebody without the right to send contracts', function () {
    [, $workspace] = contractSenderInWorkspace();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    actingAs($member)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Huurovereenkomst',
            'file' => uploadedPdf(),
        ])
        ->assertForbidden();
});

it('hands the document back to its author, inline and without sniffing', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => uploadedPdf(),
    ]);

    actingAs($user)
        ->get(route('chat.contracts.source', [$workspace, Contract::sole()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename="huurovereenkomst.pdf"');
});

it('offers the document as a download when asked', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => uploadedPdf(),
    ]);

    actingAs($user)
        ->get(route('chat.contracts.source', [$workspace, Contract::sole()]).'?download=1')
        ->assertHeader('Content-Disposition', 'attachment; filename="huurovereenkomst.pdf"');
});

it('keeps the document away from colleagues it was not shown to', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => uploadedPdf(),
    ]);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    /*
     * Being in the workspace is not enough, and that is the narrower line a
     * contract draws than a form: what is in here is usually somebody's salary,
     * their address or their terms.
     */
    actingAs($colleague)
        ->get(route('chat.contracts.source', [$workspace, Contract::sole()]))
        ->assertForbidden();
});

it('says the server is missing something, not that the file is broken', function () {
    [$user, $workspace] = contractSenderInWorkspace();

    // The exact failure a fresh install hits: the binary is not on the web
    // user's PATH, which is not the same PATH the shell has.
    config()->set('contracts.ghostscript', 'gs-dat-niet-bestaat');

    $errors = actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Huurovereenkomst',
            'file' => uploadedPdf(),
        ])
        ->assertSessionHasErrors('file')
        ->getSession()
        ->get('errors');

    /*
     * The distinction that cost an afternoon. "Deze PDF is beschadigd" is
     * something a person can act on; this is not — they can try every file they
     * own and every one will be refused, with a message telling them to look at
     * their document.
     */
    expect($errors->first('file'))
        ->toBe(__('contracts.upload.no_processor'))
        ->not->toBe(__('contracts.upload.unreadable'));
});
