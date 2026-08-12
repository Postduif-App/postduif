<?php

use App\Enums\ContractFieldType;
use App\Enums\ContractProgressKind;
use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Contracts as ContractsFeature;
use App\Features\WorkspaceFeature;
use App\Mail\ContractRequestMail;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ContractProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use setasign\Fpdi\Tcpdf\Fpdi;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The whole thing, once, from one end to the other.
 *
 * Every other suite proves one link of the chain. This one exists because a
 * chain of proven links is not the same as a chain: what it watches for is the
 * seam where two correct pieces disagree — an id that changes shape, a status
 * that has to be reached before the next step will run, a document that exists
 * a moment after somebody is told it does.
 *
 * Nothing is faked that carries the story. The queue runs inline, so the PDF is
 * genuinely composed; only the outgoing mail and the notification are held, and
 * only so they can be inspected.
 */
beforeEach(function () {
    $binary = (string) config('contracts.ghostscript');

    if ($binary === '' || (! is_executable($binary) && shell_exec('command -v '.escapeshellarg($binary)) === null)) {
        $this->markTestSkipped('Ghostscript is not installed; the chain cannot be walked.');
    }
});

it('carries a contract from an upload to a signed PDF', function () {
    Storage::fake('local');
    Mail::fake();
    Notification::fake();

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    /* ---------------------------------------------------------------- 1/7
     * The author uploads a PDF.
     *
     * A real one: it goes through Ghostscript, gets rewritten, hashed and
     * counted, and every step after this depends on that having happened.
     */
    actingAs($author)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Huurovereenkomst 2026',
            'message' => 'Graag voor vrijdag tekenen.',
            'file' => uploadedPdf(pages: 2),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract = Contract::sole();

    expect($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->page_count)->toBe(2)
        ->and($contract->source_hash)->not->toBeNull();

    /* ---------------------------------------------------------------- 2/7
     * Boxes are drawn over the pages: one to type into, one to sign in.
     */
    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [
                [
                    'page' => 1,
                    'x' => 0.15, 'y' => 0.30, 'width' => 0.35, 'height' => 0.03,
                    'type' => ContractFieldType::Text->value,
                    'label' => 'Naam huurder',
                    'is_required' => true,
                ],
                [
                    'page' => 2,
                    'x' => 0.15, 'y' => 0.75, 'width' => 0.26, 'height' => 0.08,
                    'type' => ContractFieldType::Signature->value,
                    'label' => 'Handtekening huurder',
                    'is_required' => true,
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($contract->fields()->count())->toBe(2);

    /* ---------------------------------------------------------------- 3/7
     * It goes out to somebody who has no account here at all.
     */
    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
            'valid_for_days' => 14,
            'notify_channel_id' => $channel->id,
        ])
        ->assertSessionHasNoErrors();

    Mail::assertSent(
        ContractRequestMail::class,
        fn (ContractRequestMail $mail): bool => $mail->hasTo('anna@example.com'),
    );

    $signer = $contract->fresh()->signers()->sole();

    expect($contract->fresh()->status)->toBe(ContractStatus::Sent);

    /* ---------------------------------------------------------------- 4/7
     * Anna opens her link. No session, no account — the token is everything.
     */
    get(route('contracts.sign.show', $signer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('state', 'signing')
            ->where('contract.signerName', 'Anna de Vries')
            // Only her own boxes, and both of them are hers.
            ->has('contract.fields', 2));

    expect($signer->fresh()->opened_at)->not->toBeNull();

    /* ---------------------------------------------------------------- 5/7
     * She fills it in and draws her signature.
     */
    $typed = $contract->fields()->where('type', ContractFieldType::Text)->sole();

    post(route('contracts.sign.store', $signer->token), [
        'values' => [$typed->id => 'Anna de Vries'],
    ])->assertSessionHasNoErrors();

    post(route('contracts.sign.signature', $signer->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => UploadedFile::fake()->image('signature.png', 300, 100),
    ])->assertSessionHasNoErrors();

    /* ---------------------------------------------------------------- 6/7
     * And signs. This is the step that cannot be taken back.
     */
    post(route('contracts.sign.complete', $signer->token), [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)',
    ])->assertSessionHasNoErrors();

    $signer->refresh();
    $contract->refresh();

    expect($signer->signed_at)->not->toBeNull()
        ->and($signer->ip_address)->not->toBeNull()
        ->and($signer->signed_document_hash)->toBe($contract->source_hash)
        ->and($contract->status)->toBe(ContractStatus::Completed)
        ->and($contract->completed_at)->not->toBeNull();

    /* ---------------------------------------------------------------- 7/7
     * The author hears about it, and the document is there to be had.
     *
     * The queue is inline in tests, so the job that composes the PDF has
     * already run by the time the request came back — which is exactly the
     * ordering the notification depends on: it carries a download link.
     */
    Notification::assertSentTo(
        $author,
        ContractProgress::class,
        fn (ContractProgress $notification): bool => $notification->kind === ContractProgressKind::Completed
            && $notification->downloadUrl !== null,
    );

    // The bot said so in the channel the author named, and the card grows out
    // of the link in it.
    expect(Message::query()->where('channel_id', $channel->id)->count())->toBeGreaterThan(0);

    $signed = $contract->fresh()->signedCopy();

    expect($signed)->not->toBeNull()
        // Two pages of contract and one of audit trail.
        ->and((new Fpdi)->setSourceFile($signed->getPath()))->toBe(3);

    // The author downloads it through the policy.
    actingAs($author)
        ->get(route('chat.contracts.download', [$workspace, $contract]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // And Anna, who has no account, gets her own copy behind her own token.
    get(route('contracts.sign.copy', $signer->token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // Her link now explains that she is done rather than offering the form
    // again.
    get(route('contracts.sign.show', $signer->token))
        ->assertInertia(fn ($page) => $page->where('state', 'signed'));
});

it('keeps every contract file off the public disk', function () {
    Storage::fake('local');
    Storage::fake('public');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    actingAs($author)->post(route('chat.contracts.store', $workspace), [
        'title' => 'Huurovereenkomst',
        'file' => uploadedPdf(pages: 1),
    ]);

    $contract = Contract::sole();

    /*
     * And the other two kinds of file this feature makes: the mark somebody
     * draws, which is a picture of their name, and the finished document.
     */
    $signer = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    $signer->addMedia(UploadedFile::fake()->image('signature.png', 200, 80))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    $contract->addMedia(realPdf(1))->toMediaCollection(Contract::SIGNED);

    /*
     * The check the bead asks for, made permanent. A contract on a public disk
     * would be one guessed URL away from a stranger, and the URL would go on
     * working after the contract was withdrawn — which is every limit the
     * feature has. The signature is worse still: it is a picture of a person's
     * name, and a public path would leave it a guess away from anybody who
     * wanted to paste it onto something else.
     */
    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->not->toBeEmpty();

    expect($contract->source()->disk)->toBe('local')
        ->and($contract->fresh()->signedCopy()->disk)->toBe('local')
        ->and($signer->fresh()->signature()->disk)->toBe('local');
});

it('throttles every public signing route', function () {
    /*
     * The other half of the bead's audit. These nine addresses are the whole of
     * what the outside world can reach, and every one of them is opened with
     * nothing but a token — so a stream of guesses has to cost something.
     *
     * Read off the router rather than eyeballed, because route:list reports no
     * middleware at all for a prefix group with a parameter in it, which makes
     * "I looked and they were fine" an unreliable thing to have done.
     */
    $unthrottled = collect(app('router')->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'contracts.sign'))
        ->reject(fn ($route): bool => collect($route->middleware())
            ->contains(fn ($middleware): bool => str_starts_with((string) $middleware, 'throttle')))
        ->map(fn ($route): string => (string) $route->getName())
        ->values();

    expect($unthrottled)->toBeEmpty()
        // And there really are nine of them, so a route added later without one
        // cannot slip past by the filter finding nothing to check.
        ->and(collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'contracts.sign'))
            ->count())->toBe(9);
});

it('offers contracts and the right to send them on the public site', function () {
    /*
     * An empty platform shows the onboarding screen on these very addresses —
     * see RedirectToInstallation — so the platform has to exist first.
     */
    installedPlatform();

    $props = get(route('home'))->assertOk()->viewData('page')['props'];

    /*
     * Nothing had to be written for either of these: the page is derived from
     * WorkspaceFeature::ALL and WorkspaceAbility::cases(), so registering the
     * class and the case is what puts them here. MarketingTest already checks
     * the counts; what this adds is the names, which a count cannot — a feature
     * registered with a missing translation passes a count and reads as
     * "features.contracts.label" on the page.
     */
    $feature = collect($props['features'])->firstWhere('key', 'contracts');

    expect($feature)->not->toBeNull()
        ->and($feature['label'])->toBe(__('features.contracts.label'))
        ->and($feature['label'])->not->toStartWith('features.')
        // Off until a workspace says otherwise, with the rest of the group that
        // reaches past the workspace.
        ->and($feature['onByDefault'])->toBeFalse();

    $ability = collect($props['abilities'])
        ->firstWhere('value', WorkspaceAbility::SendContracts->value);

    expect($ability)->not->toBeNull()
        ->and($ability['label'])->toBe(WorkspaceAbility::SendContracts->label())
        ->and($ability['label'])->not->toStartWith('enums.');

    /*
     * The description is not on this page — it belongs beside the tickbox in
     * the settings screen, where somebody is deciding whether to grant it. It
     * still has to resolve, and a missing one shows up as the key.
     */
    expect(WorkspaceAbility::SendContracts->description())->not->toStartWith('enums.');

    expect(WorkspaceFeature::ALL)->toContain(ContractsFeature::class);
});

it('offers the contracts screen in the rail, and only where it leads somewhere', function () {
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    /*
     * Off first. The feature is switched off until a workspace says otherwise,
     * and the rail must not offer a screen whose routes answer 404.
     */
    expect($author->can('createContract', $workspace))->toBeFalse();

    Feature::for($workspace)->activate(ContractsFeature::class);

    expect($author->fresh()->can('createContract', $workspace->fresh()))->toBeTrue();

    /*
     * And the half that is about the person rather than the workspace: a member
     * without the right gets no entry, because the screen behind it would
     * refuse them.
     */
    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    expect($member->can('createContract', $workspace))->toBeFalse();

    actingAs($author)
        ->get(route('chat.contracts.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.contracts', true));

    actingAs($member)
        ->get(route('chat.contracts.index', $workspace))
        ->assertForbidden();
});
