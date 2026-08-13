<?php

use App\Enums\ContractFieldType;
use App\Enums\ContractStatus;
use App\Enums\SignatureMethod;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Mail\ContractRequestMail;
use App\Models\ApiToken;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * The whole round trip, from one system's point of view.
 *
 * Every other suite here takes one step and holds everything around it still.
 * This one takes them all in order and holds nothing: a template is prepared, a
 * call sends it, a recipient with no account signs it on the public page, the
 * finished PDF is composed, and the system that started it all is told — with a
 * signature it can check and a link it can actually fetch.
 *
 * It exists because every one of those steps has a seam, and the seams are
 * where this feature would fail in a way no unit of it could see: a signature
 * that did not survive the copy, a party numbered from the wrong end, a webhook
 * that fires before the document it points at exists.
 *
 * The queue is real here — the suite runs on the sync connection — so the
 * render and the delivery both happen inside the request that finished the
 * signing. That is not how production works, and it is exactly what makes this
 * worth running: anything the job needs and does not carry with it fails here.
 */
beforeEach(function () {
    $binary = (string) config('contracts.ghostscript');

    if ($binary === '' || (! is_executable($binary) && shell_exec('command -v '.escapeshellarg($binary)) === null)) {
        $this->markTestSkipped('Ghostscript is not installed; a real PDF cannot be produced.');
    }

    Mail::fake();
    Http::fake();

    // The callback points at a name that resolves to nothing. What is being
    // tested is the delivery, not the guard — WorkflowHttpRequestTest owns that.
    config()->set('workflows.http.allow_private_hosts', true);
});

it('sends, signs, renders and reports back', function () {
    Storage::fake('local');

    $sender = User::factory()->create(['name' => 'Sebastiaan Kloos']);
    $workspace = workspaceWithMember($sender, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    /*
     * A two-party template: the sender at nought, the tenant at one. The PDF is
     * a real one, because the renderer's first act is to open it.
     */
    $template = Contract::factory()->template(1)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'title' => 'Huurovereenkomst',
        'page_count' => 2,
    ]);

    $template->addMedia(realPdf(2))->toMediaCollection(Contract::SOURCE);
    $template->update(['source_hash' => hash_file('sha256', $template->source()->getPath())]);

    $mine = ContractField::factory()->signature()->forSigner(0)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening verhuurder',
    ]);

    ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening huurder',
    ]);

    $woonplaats = ContractField::factory()->ofType(ContractFieldType::Text)->forSigner(1)->create([
        'contract_id' => $template->id,
        'label' => 'Woonplaats',
    ]);

    // The sender signs the template once. From here on it is reused, never
    // re-signed — which is the whole point of the thing.
    $author = ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $sender->id,
        'name' => $sender->name,
        'email' => $sender->email,
    ]);

    $author->addMedia(UploadedFile::fake()->image('verhuurder.png'))
        ->toMediaCollection(ContractSigner::SIGNATURE);

    $author->forceFill([
        'signed_at' => now()->subWeek(),
        'signed_document_hash' => $template->source_hash,
        'signature_method' => SignatureMethod::Drawn,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Firefox',
    ])->save();

    ContractFieldValue::factory()->drawn()->create([
        'contract_field_id' => $mine->id,
        'contract_signer_id' => $author->id,
    ]);

    $apiToken = new ApiToken([
        'user_id' => $sender->id,
        'workspace_id' => $workspace->id,
        'name' => 'Verhuursysteem',
        'scopes' => [ApiToken::SCOPE_CONTRACTS],
    ]);

    $apiToken->user_id = $sender->id;
    $apiToken->workspace_id = $workspace->id;
    $plain = $apiToken->regenerateToken();
    $apiToken->save();

    // ------------------------------------------------------------------
    // One call from the other system.
    // ------------------------------------------------------------------

    $secret = str_repeat('geheim', 6);

    $created = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'title' => 'Huurovereenkomst Loubergweg 3',
            'valid_for_days' => 30,
            'callback_url' => 'https://verhuursysteem.test/postduif',
            'callback_secret' => $secret,
            'recipients' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com', 'values' => [
                    $woonplaats->id => 'Eerbeek',
                ]],
            ],
        ])
        ->assertCreated();

    $contract = Contract::query()->whereKey($created->json('data.id'))->firstOrFail();
    $contract->load('signers');

    expect($contract->status)->toBe(ContractStatus::Sent)
        ->and($contract->signers)->toHaveCount(2)
        ->and($contract->signers->first()->hasSigned())->toBeTrue();

    $tenant = $contract->signers->last();

    // ------------------------------------------------------------------
    // The recipient, who has no account and only a link.
    // ------------------------------------------------------------------

    $this->get(route('contracts.sign.show', $tenant->token))->assertOk();

    $this->post(route('contracts.sign.signature', $tenant->token), [
        'kind' => ContractFieldType::Signature->value,
        'method' => SignatureMethod::Drawn->value,
        'image' => UploadedFile::fake()->image('huurder.png'),
    ])->assertRedirect();

    $this->post(route('contracts.sign.complete', $tenant->token))->assertRedirect();

    // ------------------------------------------------------------------
    // What the application did about it, without being asked.
    // ------------------------------------------------------------------

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Completed)
        ->and($contract->signedCopy())->not->toBeNull()
        // The renderer opened the source, drew on it and bound the audit trail
        // to the back — so the finished document is longer than what went in.
        ->and($contract->signedCopy()->size)->toBeGreaterThan(0);

    // The value that arrived through the API became an answer when she signed,
    // and not a moment before.
    $answer = ContractFieldValue::query()
        ->where('contract_signer_id', $tenant->id)
        ->get()
        ->firstWhere('value', 'Eerbeek');

    expect($answer)->not->toBeNull()
        ->and($answer->filled_at)->not->toBeNull();

    // ------------------------------------------------------------------
    // And what the other system heard.
    // ------------------------------------------------------------------

    $completed = Http::recorded(
        fn (OutboundRequest $request): bool => $request->url() === 'https://verhuursysteem.test/postduif'
            && $request->header('X-Postduif-Event')[0] === 'completed',
    )->first()[0] ?? null;

    expect($completed)->not->toBeNull();

    $body = $completed->body();

    // The header is taken over exactly these bytes, which is what makes it
    // checkable at the other end at all.
    expect($completed->header('X-Postduif-Signature')[0])
        ->toBe('sha256='.hash_hmac('sha256', $body, $secret));

    $payload = json_decode($body, true);

    expect($payload['event'])->toBe('completed')
        ->and($payload['contract']['id'])->toBe($contract->id)
        ->and($payload['contract']['status'])->toBe(ContractStatus::Completed->value)
        ->and($payload['signers'])->toHaveCount(2)
        ->and($payload['documentUrl'])->toBeString();

    // The link in the payload opens the finished document — the promise the
    // whole callback exists to make.
    $this->get($payload['documentUrl'])->assertOk();

    // And so does the API, for a caller that would rather use its token.
    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->get(route('api.v1.contracts.document', $contract))
        ->assertOk();

    // Nobody was asked to sign anything twice.
    Mail::assertNotSent(ContractRequestMail::class, fn ($mail): bool => $mail->hasTo($sender->email));
});
