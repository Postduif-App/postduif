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
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * Sending a contract from somebody else's system.
 *
 * The epic in one suite: a workspace prepares a template with its own signature
 * on it, and after that a lease goes out on an HTTP call. What is being watched
 * here is mostly the boundary rather than the happy path — a token that was not
 * made for this, a template that belongs to another workspace, a recipient
 * count that does not match the boxes on the page. Every one of those, gone
 * wrong, produces a real contract sent to a real person with a signature under
 * it, and none of them is undoable.
 */
beforeEach(function () {
    Queue::fake();
    Mail::fake();
});

/**
 * A workspace with contracts on, a member who may send them, a token minted for
 * exactly that, and a template ready to go.
 *
 * @return array{0: Workspace, 1: User, 2: Contract, 3: string}
 */
function contractApiSetup(int $requiredSigners = 1, bool $preSigned = true): array
{
    Storage::fake('local');

    $sender = User::factory()->create();
    $workspace = workspaceWithMember($sender, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $template = Contract::factory()->template($requiredSigners)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'title' => 'Huurovereenkomst',
        'page_count' => 1,
    ]);

    $template->addMedia(UploadedFile::fake()->create('huurovereenkomst.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    $template->update(['source_hash' => hash_file('sha256', $template->source()->getPath())]);

    if ($preSigned) {
        $author = ContractSigner::factory()->inPosition(0)->create([
            'contract_id' => $template->id,
            'user_id' => $sender->id,
            'name' => $sender->name,
            'email' => $sender->email,
        ]);

        $author->addMedia(UploadedFile::fake()->image('handtekening.png'))
            ->toMediaCollection(ContractSigner::SIGNATURE);

        $author->forceFill([
            'signed_at' => now()->subDay(),
            'signed_document_hash' => $template->source_hash,
            'signature_method' => SignatureMethod::Drawn,
        ])->save();

        ContractField::factory()->signature()->forSigner(0)->create([
            'contract_id' => $template->id,
            'label' => 'Handtekening verhuurder',
        ]);
    }

    $offset = $preSigned ? 1 : 0;

    for ($recipient = 0; $recipient < $requiredSigners; $recipient++) {
        ContractField::factory()->signature()->forSigner($recipient + $offset)->create([
            'contract_id' => $template->id,
            'label' => 'Handtekening huurder',
        ]);

        ContractField::factory()->ofType(ContractFieldType::Text)->forSigner($recipient + $offset)->create([
            'contract_id' => $template->id,
            'label' => 'Woonplaats',
        ]);
    }

    return [$workspace, $sender, $template->fresh(['fields', 'signers']), contractApiToken($sender, $workspace)];
}

/** A token tied to one workspace and allowed to do contracts with it. */
function contractApiToken(User $user, ?Workspace $workspace, array $scopes = [ApiToken::SCOPE_CONTRACTS]): string
{
    $token = new ApiToken([
        'user_id' => $user->id,
        'workspace_id' => $workspace?->id,
        'name' => 'Verhuursysteem',
        'scopes' => $scopes,
    ]);

    $token->user_id = $user->id;
    $token->workspace_id = $workspace?->id;

    $plain = $token->regenerateToken();
    $token->save();

    return $plain;
}

/** @return array<string, string> */
function bearer(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

it('lists the templates a token may send', function () {
    [$workspace, , $template, $token] = contractApiSetup(requiredSigners: 1);

    // Somebody else's template, to prove the list is not simply "all templates".
    Contract::factory()->template()->create();

    // And a real contract in the same workspace, which is not a template.
    Contract::factory()->sent()->create(['workspace_id' => $workspace->id]);

    $response = $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contract-templates.index'))
        ->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $template->id)
        ->assertJsonPath('data.0.requiredSigners', 1)
        ->assertJsonPath('data.0.partyCount', 2)
        ->assertJsonPath('data.0.preSigned', true)
        ->assertJsonPath('data.0.readyToSend', true)
        // The signature box is left out: nobody can send a drawing in.
        ->assertJsonCount(1, 'data.0.parties.0.fields')
        ->assertJsonPath('data.0.parties.0.fields.0.label', 'Woonplaats');
});

it('refuses a token that was not made for contracts', function () {
    [$workspace, $sender] = contractApiSetup();

    $token = contractApiToken($sender, $workspace, scopes: []);

    $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contract-templates.index'))
        ->assertForbidden();
});

it('refuses a token that is not tied to a workspace', function () {
    [, $sender] = contractApiSetup();

    $token = contractApiToken($sender, null);

    $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contract-templates.index'))
        ->assertForbidden();
});

it('sends a template to the person named in the call', function () {
    [, $sender, $template, $token] = contractApiSetup();

    $woonplaats = $template->fields->firstWhere('label', 'Woonplaats');

    $response = $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'title' => 'Huurovereenkomst Loubergweg 3',
            'valid_for_days' => 14,
            'recipients' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com', 'values' => [
                    $woonplaats->id => 'Eerbeek',
                ]],
            ],
        ])
        ->assertCreated();

    $contract = Contract::query()->whereKey($response->json('data.id'))->firstOrFail();

    expect($contract->title)->toBe('Huurovereenkomst Loubergweg 3')
        ->and($contract->status)->toBe(ContractStatus::Sent)
        ->and($contract->is_template)->toBeFalse()
        ->and($contract->expires_at)->not->toBeNull();

    $contract->load(['signers', 'fields']);

    // The sender is on it, signed, without having been asked again.
    expect($contract->signers)->toHaveCount(2)
        ->and($contract->signers->first()->hasSigned())->toBeTrue()
        ->and($contract->signers->last()->email)->toBe('anna@example.com');

    Mail::assertSent(ContractRequestMail::class, 1);
    Mail::assertSent(ContractRequestMail::class, fn (ContractRequestMail $mail): bool => $mail->hasTo('anna@example.com'));

    // What was filled in ahead of time is on the page as a draft — visible to
    // the recipient, changeable by them, and not yet an answer.
    $value = ContractFieldValue::query()
        ->where('contract_signer_id', $contract->signers->last()->id)
        ->firstOrFail();

    expect($value->value)->toBe('Eerbeek')
        ->and($value->filled_at)->toBeNull();

    $response->assertJsonPath('data.signers.1.email', 'anna@example.com')
        ->assertJsonPath('data.signers.1.signedAt', null);
});

it('never puts a signing link in the response', function () {
    [, , $template, $token] = contractApiSetup();

    $response = $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertCreated();

    $contract = Contract::query()->whereKey($response->json('data.id'))->firstOrFail();

    foreach ($contract->signers as $signer) {
        expect($response->content())->not->toContain($signer->token);
    }
});

it('insists on exactly as many recipients as the template expects', function () {
    [, , $template, $token] = contractApiSetup(requiredSigners: 2);

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');

    expect(Contract::query()->realContracts()->count())->toBe(0);
});

it('refuses two recipients at the same address', function () {
    [, , $template, $token] = contractApiSetup(requiredSigners: 2);

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
                ['name' => 'Anna de V.', 'email' => 'ANNA@example.com'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');
});

it('refuses a recipient who already signed the template as the sender', function () {
    [, $sender, $template, $token] = contractApiSetup();

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => $sender->name, 'email' => $sender->email]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');
});

it('refuses a template that is not finished being prepared', function () {
    [$workspace, $sender, , $token] = contractApiSetup();

    $unfinished = Contract::factory()->template()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $unfinished->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertStatus(422);
});

it('cannot reach a template in another workspace', function () {
    [, , , $token] = contractApiSetup();

    $elsewhere = Contract::factory()->template()->create();

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $elsewhere->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertNotFound();
});

it('refuses a value for a box that belongs to somebody else', function () {
    [, , $template, $token] = contractApiSetup(requiredSigners: 2);

    // A box drawn for the second recipient, handed to the first.
    $theirs = $template->fields->where('label', 'Woonplaats')->last();

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com', 'values' => [$theirs->id => 'Eerbeek']],
                ['name' => 'Bram Jansen', 'email' => 'bram@example.com'],
            ],
        ])
        ->assertStatus(422);
});

it('refuses a callback that points inside the network', function () {
    [, , $template, $token] = contractApiSetup();

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
            'callback_url' => 'http://169.254.169.254/latest/meta-data/',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('callback_url');

    expect(Contract::query()->realContracts()->count())->toBe(0);
});

it('keeps the callback with the contract it was given for', function () {
    [, , $template, $token] = contractApiSetup();

    // The guard resolves every address it is given; letting private hosts
    // through is how the workflow suite keeps DNS out of a unit of work.
    config()->set('workflows.http.allow_private_hosts', true);

    $response = $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
            'callback_url' => 'https://verhuursysteem.test/postduif',
            'callback_secret' => str_repeat('s', 32),
        ])
        ->assertCreated();

    $contract = Contract::query()->whereKey($response->json('data.id'))->firstOrFail();

    expect($contract->callback_url)->toBe('https://verhuursysteem.test/postduif')
        ->and($contract->callback_secret)->toBe(str_repeat('s', 32))
        // Never in the body. It is a credential the caller already holds, and
        // echoing one back is how they end up in logs.
        ->and($response->content())->not->toContain(str_repeat('s', 32));
});

it('mints a callback secret when the caller did not bring one', function () {
    [, , $template, $token] = contractApiSetup();

    config()->set('workflows.http.allow_private_hosts', true);

    $response = $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
            'callback_url' => 'https://verhuursysteem.test/postduif',
        ])
        ->assertCreated();

    $secret = $response->json('callbackSecret');

    $contract = Contract::query()->whereKey($response->json('data.id'))->firstOrFail();

    // Nothing is ever delivered unsigned, so a bare URL would otherwise be a
    // callback that quietly never fires. See DeliverContractWebhooks.
    expect($secret)->toBeString()
        ->and($contract->callback_secret)->toBe($secret);
});

it('refuses a secret without somewhere to send it', function () {
    [, , $template, $token] = contractApiSetup();

    $this->withHeaders(bearer($token))
        ->postJson(route('api.v1.contracts.store'), [
            'template_id' => $template->id,
            'recipients' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
            'callback_secret' => str_repeat('s', 32),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('callback_secret');
});

it('reports how far a contract has got', function () {
    [$workspace, $sender, , $token] = contractApiSetup();

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    ContractSigner::factory()->signed()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
        'email' => 'anna@example.com',
    ]);

    $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contracts.show', $contract))
        ->assertOk()
        ->assertJsonPath('data.status', ContractStatus::Sent->value)
        ->assertJsonPath('data.signedCopy', 'none')
        ->assertJsonPath('data.signers.0.email', 'anna@example.com')
        ->assertJsonMissing(['token']);
});

it('does not hand out a contract from another workspace', function () {
    [, , , $token] = contractApiSetup();

    $elsewhere = Contract::factory()->sent()->create();

    $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contracts.show', $elsewhere))
        ->assertNotFound();
});

it('lists what is still outstanding', function () {
    [$workspace, $sender, , $token] = contractApiSetup();

    Contract::factory()->sent()->create(['workspace_id' => $workspace->id, 'created_by' => $sender->id]);
    Contract::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $sender->id]);

    $this->withHeaders(bearer($token))
        ->getJson(route('api.v1.contracts.index'))
        ->assertOk()
        // The draft is left out; a template was never in the running.
        ->assertJsonCount(1, 'data');
});

it('says the document is not there yet rather than pretending it never will be', function () {
    [$workspace, $sender, , $token] = contractApiSetup();

    $contract = Contract::factory()->completed()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    $this->withHeaders(bearer($token))
        ->get(route('api.v1.contracts.document', $contract))
        ->assertStatus(409);
});

it('hands over the signed document once it exists', function () {
    [$workspace, $sender, , $token] = contractApiSetup();

    $contract = Contract::factory()->completed()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    $contract->addMedia(UploadedFile::fake()->create('ondertekend.pdf', 30))
        ->toMediaCollection(Contract::SIGNED);

    $this->withHeaders(bearer($token))
        ->get(route('api.v1.contracts.document', $contract))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
