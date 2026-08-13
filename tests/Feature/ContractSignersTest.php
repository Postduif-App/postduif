<?php

use App\Enums\ContractStatus;
use App\Enums\SystemRole;
use App\Mail\ContractRequestMail;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

/**
 * Writing down who is going to sign, before anybody is asked.
 *
 * The step that makes a two-party contract layable-out at all: a box belongs to
 * a person, and until the people have names the editor can only offer numbers.
 * Nothing here reaches an inbox — that is the whole reason it is not sending.
 */
it('writes the signers down without asking any of them', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
                ['name' => 'Bram Jansen', 'email' => 'bram@example.com'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract->refresh();

    expect($contract->signers()->orderBy('signing_order')->pluck('name')->all())
        ->toBe(['Anna de Vries', 'Bram Jansen'])
        // Still a draft: naming people is not the same as asking them.
        ->and($contract->status)->toBe(ContractStatus::Draft);

    Mail::assertNothingSent();
});

/**
 * A signer with no account is the ordinary case rather than the exception.
 *
 * Most people asked to sign something are customers who will never have a
 * login here, and the token in their link is the whole of their permission.
 */
it('takes an address belonging to nobody with an account', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Carla Buitenstaander', 'email' => 'carla@example.com'],
            ],
        ])
        ->assertSessionHasNoErrors();

    $signer = $contract->signers()->sole();

    expect($signer->user_id)->toBeNull()
        ->and($signer->token)->toHaveLength(64);
});

/**
 * Saving twice must not quietly reissue the links.
 *
 * On a draft nobody is holding one yet, but this is the same action sending
 * runs — and there a rotated token is a link somebody already has that stops
 * working.
 */
it('leaves a signer who is still on the list with the token they had', function () {
    [$author, $workspace, $contract] = sendableContract();

    $save = fn (array $signers) => actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => $signers,
        ])
        ->assertSessionHasNoErrors();

    $save([['name' => 'Anna de Vries', 'email' => 'anna@example.com']]);

    $token = $contract->signers()->sole()->token;

    $save([
        ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
        ['name' => 'Bram Jansen', 'email' => 'bram@example.com'],
    ]);

    expect($contract->signers()->where('email', 'anna@example.com')->sole()->token)
        ->toBe($token);
});

/**
 * The rule that stops a reorder from being a silent disaster.
 *
 * Boxes point at a position, not at a person. Putting the author at the head of
 * the queue — which is what "ik onderteken zelf ook" does — would otherwise
 * hand the recipient's signature box to the author, a change nobody made and
 * nobody would see.
 */
it('sends every box after the person it was drawn for when the order changes', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertSessionHasNoErrors();

    $hers = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
        'signer_index' => 0,
    ]);

    // The author joins, in front. Anna is now the second signer.
    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => $author->name, 'email' => $author->email, 'user_id' => $author->id],
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect($hers->refresh()->signer_index)->toBe(1)
        ->and($contract->signers()->where('email', 'anna@example.com')->sole()->signing_order)
        ->toBe(1);
});

/**
 * A box whose person was taken off the list has nowhere to follow.
 *
 * Left with the last signer rather than deleted: throwing it away would throw
 * away geometry somebody drew, and pointing it at nobody would leave a required
 * field no living signer can satisfy.
 */
it('leaves an orphaned box with somebody real', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
                ['name' => 'Bram Jansen', 'email' => 'bram@example.com'],
            ],
        ]);

    $his = ContractField::factory()->signature()->create([
        'contract_id' => $contract->id,
        'signer_index' => 1,
    ]);

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna de Vries', 'email' => 'anna@example.com']],
        ])
        ->assertSessionHasNoErrors();

    expect($his->refresh()->signer_index)->toBe(0);
});

/**
 * Two boxes, two people, two signatures — the thing the whole step is for.
 *
 * Sending inherits the list that was saved, so what somebody laid the contract
 * out against is what goes in the post.
 */
it('carries the saved list through to the invitations', function () {
    [$author, $workspace, $contract] = sendableContract();

    $signers = [
        ['name' => $author->name, 'email' => $author->email, 'user_id' => $author->id],
        ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
    ];

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), ['signers' => $signers]);

    ContractField::factory()->signature()->create(['contract_id' => $contract->id, 'signer_index' => 0]);
    ContractField::factory()->signature()->create(['contract_id' => $contract->id, 'signer_index' => 1]);

    actingAs($author)
        ->post(route('chat.contracts.send', [$workspace, $contract]), ['signers' => $signers])
        ->assertSessionHasNoErrors();

    expect($contract->refresh()->status)->toBe(ContractStatus::Sent)
        ->and($contract->signers()->count())->toBe(2);

    // Including the author: signing something you sent is signing it, and the
    // link is what carries the proof of who put the mark down.
    Mail::assertSent(ContractRequestMail::class, 2);
    Mail::assertSent(
        ContractRequestMail::class,
        fn (ContractRequestMail $mail) => $mail->hasTo($author->email),
    );
});

/**
 * An author who signs their own contract keeps the screen that runs it.
 *
 * A signer with an account is ordinarily sent straight to their own page, and
 * that is right for a colleague who was asked. It is wrong for the author: it
 * would take reminding and withdrawing away from the one person most likely to
 * need them, for as long as they have not signed. They get a link instead.
 */
it('does not bounce the author to their own signing page', function () {
    [$author, $workspace, $contract] = sendableContract(['status' => ContractStatus::Sent]);

    $signer = ContractSigner::factory()->forUser($author)->create([
        'contract_id' => $contract->id,
    ]);

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'contract.mySignUrl',
            route('contracts.sign.show', $signer->token),
        ));
});

it('refuses the same address twice', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => 'Anna', 'email' => 'anna@example.com'],
                ['name' => 'Anna nog eens', 'email' => 'ANNA@example.com'],
            ],
        ])
        ->assertSessionHasErrors('signers');

    expect($contract->signers()->count())->toBe(0);
});

/**
 * The same line ContractPolicy::update draws, and for the same reason: renaming
 * a signer after they signed would rewrite who agreed to what.
 */
it('will not rewrite the list once somebody has signed', function () {
    [$author, $workspace, $contract] = sendableContract(['status' => ContractStatus::Sent]);

    ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        'email' => 'anna@example.com',
        'signed_at' => now(),
    ]);

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [['name' => 'Iemand anders', 'email' => 'iemand@example.com']],
        ])
        ->assertForbidden();
});

it('is not open to a colleague who did not send it', function () {
    [, $workspace, $contract] = sendableContract();

    $bystander = User::factory()->create();
    joinWorkspace($workspace, $bystander, SystemRole::Member);

    actingAs($bystander)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [['name' => 'Anna', 'email' => 'anna@example.com']],
        ])
        ->assertForbidden();
});

/** What the editor is handed, which is the point of saving the list first. */
it('gives the editor names to put beside the boxes', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => $author->name, 'email' => $author->email, 'user_id' => $author->id],
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
            ],
        ]);

    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $contract]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contract.signers.0.name', $author->name)
            ->where('contract.signers.0.index', 0)
            ->where('contract.signers.1.name', 'Anna de Vries')
            ->where('contract.signers.1.index', 1)
        );
});

/**
 * A box may now be handed to the second signer, because there is one.
 *
 * The bound in the validator is the list that exists — a box for the fourth
 * signer of a two-signer contract is one nobody would ever be shown.
 */
it('lets a box be assigned to the second signer once the list is saved', function () {
    [$author, $workspace, $contract] = sendableContract();

    actingAs($author)
        ->put(route('chat.contracts.signers', [$workspace, $contract]), [
            'signers' => [
                ['name' => $author->name, 'email' => $author->email, 'user_id' => $author->id],
                ['name' => 'Anna de Vries', 'email' => 'anna@example.com'],
            ],
        ]);

    $box = fn (int $index) => [
        'page' => 1,
        'x' => 0.1,
        'y' => 0.2,
        'width' => 0.3,
        'height' => 0.04,
        'type' => 'signature',
        'label' => 'Handtekening',
        'is_required' => true,
        'signer_index' => $index,
    ];

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [$box(0), $box(1)],
        ])
        ->assertSessionHasNoErrors();

    expect($contract->fields()->orderBy('position')->pluck('signer_index')->all())
        ->toBe([0, 1]);

    // And still not a third, which nobody would ever be shown.
    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $contract]), [
            'fields' => [$box(2)],
        ])
        ->assertSessionHasErrors('fields.0.signer_index');
});
