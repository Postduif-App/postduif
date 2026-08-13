<?php

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;

/**
 * Preparing a template from the inside, with a mouse.
 *
 * ContractTemplateTest watches the model: what a template is, and everything
 * that walks the contracts table stepping around it. This one watches the
 * screens — the tick that makes one, the number that says how many people it
 * goes to, the switch that puts the author's own signature on it, and the list
 * it is deliberately kept out of.
 *
 * The thread running through all of it is the numbering. A template counts its
 * parties instead of naming them, and the count moves the moment the author
 * joins or leaves, so every box on the document has to move with it. That is the
 * one thing here that fails quietly: nothing looks wrong afterwards, the
 * signature box has simply changed hands.
 */

/**
 * A template on the shelf: its PDF, one box, and nobody signing along yet.
 *
 * Its own builder rather than the one in ContractTemplateTest, which puts the
 * author on it from the start — half of what is tested here is what happens the
 * moment they arrive.
 *
 * @return array{0: User, 1: Workspace, 2: Contract}
 */
function templateBeingPrepared(int $requiredSigners = 1): array
{
    [$author, $workspace] = contractSenderInWorkspace();

    $template = Contract::factory()->template($requiredSigners)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst',
        'page_count' => 1,
    ]);

    $template->addMedia(UploadedFile::fake()->create('huurovereenkomst.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    return [$author, $workspace, $template->fresh(['fields', 'signers'])];
}

/**
 * Ghostscript is only needed by the one test that uploads something.
 *
 * Skipped rather than failed when it is missing, for the reason
 * ContractUploadTest spells out: that is a fact about the machine, and a red
 * suite that says nothing about the change somebody just made is one people
 * learn to ignore.
 */
function templateUploadNeedsGhostscript(): void
{
    $binary = (string) config('contracts.ghostscript');

    $found = $binary !== '' && (is_executable($binary) || shell_exec('command -v '.escapeshellarg($binary)) !== null);

    if (! $found) {
        test()->markTestSkipped('Ghostscript is not installed; the template upload cannot be exercised.');
    }
}

it('keeps an uploaded document as a template when the box is ticked', function () {
    templateUploadNeedsGhostscript();

    [$user, $workspace] = contractSenderInWorkspace();

    actingAs($user)
        ->post(route('chat.contracts.store', $workspace), [
            'title' => 'Standaard huurovereenkomst',
            'file' => uploadedPdf(),
            'valid_for_days' => 14,
            'as_template' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = Contract::sole();

    expect($template->isTemplate())->toBeTrue()
        ->and($template->status)->toBe(ContractStatus::Draft)

        // One recipient to start with, so the editor has a party to hand a box
        // to on the very first visit — see CreateContract.
        ->and($template->required_signers)->toBe(1)

        /*
         * And no deadline, although one was asked for. A template is never sent,
         * so a date on it would quietly kill the mould rather than the letter.
         */
        ->and($template->expires_at)->toBeNull();
});

it('leaves a template out of the ordinary contract list', function () {
    [$author, $workspace, $template] = templateBeingPrepared();

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst Kerkstraat 12',
    ]);

    actingAs($author)
        ->get(route('chat.contracts.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contracts')

            /*
             * The line that is easy to forget and impossible to notice
             * afterwards: a template is a draft like any unsent contract, so
             * without the scope it would sit here looking like something
             * somebody forgot to send — with a delete button beside it.
             */
            ->has('contracts', 1)
            ->where('contracts.0.id', $contract->id)

            ->has('templates', 1)
            ->where('templates.0.id', $template->id)
            ->where('templates.0.partyCount', 1)
            ->where('templates.0.signsAlong', false)
            ->where('templates.0.isReadyToSend', false)
        );
});

it('sets how many people a template goes to', function () {
    [$author, $workspace, $template] = templateBeingPrepared();

    actingAs($author)
        ->put(route('chat.contracts.template', [$workspace, $template]), [
            'required_signers' => 3,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($template->fresh()->required_signers)->toBe(3);
});

it('refuses a count the boxes already drawn will not fit in', function () {
    [$author, $workspace, $template] = templateBeingPrepared(3);

    // A box for the third party. Nobody signs along, so the recipients are
    // numbered from zero and this one belongs to the third of them.
    ContractField::factory()->signature()->forSigner(2)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening borgsteller',
    ]);

    actingAs($author)
        ->put(route('chat.contracts.template', [$workspace, $template]), [
            'required_signers' => 1,
        ])
        ->assertSessionHasErrors('required_signers');

    /*
     * The refusal matters because isReadyToSend would have gone on saying yes:
     * it counts boxes rather than asking who they are for, so a template with a
     * signature box belonging to a party it says does not exist would look
     * perfectly finished right up until somebody used it.
     */
    expect($template->fresh()->required_signers)->toBe(3);
});

it('puts the author on the template and moves every box along with them', function () {
    [$author, $workspace, $template] = templateBeingPrepared(2);

    // Two boxes, one for each recipient, numbered from zero because nobody is
    // signing along yet.
    $first = ContractField::factory()->signature()->forSigner(0)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening huurder',
    ]);

    $second = ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening medehuurder',
    ]);

    actingAs($author)
        ->put(route('chat.contracts.template.sign-along', [$workspace, $template]), [
            'signs_along' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template->refresh()->load(['fields', 'signers']);

    $signer = $template->templateSigner();

    expect($signer)->not->toBeNull()
        ->and($signer->signing_order)->toBe(0)
        ->and($signer->user_id)->toBe($author->id)
        ->and($signer->hasSigned())->toBeFalse()

        // Three parties now: the author and the two people it goes to.
        ->and($template->partyCount())->toBe(3)

        /*
         * And both boxes a place further along. Without this the author's
         * arrival would have handed the first recipient's signature box to the
         * author — a change nobody made and nobody would see.
         */
        ->and($first->fresh()->signer_index)->toBe(1)
        ->and($second->fresh()->signer_index)->toBe(2);
});

it('takes the author back off and hands the boxes back', function () {
    [$author, $workspace, $template] = templateBeingPrepared(2);

    ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
    ]);

    $mine = ContractField::factory()->signature()->forSigner(0)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening verhuurder',
    ]);

    $theirs = ContractField::factory()->signature()->forSigner(1)->create([
        'contract_id' => $template->id,
        'label' => 'Handtekening huurder',
    ]);

    actingAs($author)
        ->put(route('chat.contracts.template.sign-along', [$workspace, $template]), [
            'signs_along' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template->refresh()->load(['fields', 'signers']);

    expect($template->templateSigner())->toBeNull()
        ->and($template->partyCount())->toBe(2)

        // The recipient's box follows the recipient down to zero.
        ->and($theirs->fresh()->signer_index)->toBe(0)

        /*
         * And the author's own box is kept rather than deleted, clamped onto the
         * first party who does exist. The same answer SaveContractSigners gives
         * when somebody is taken off a list: losing it would lose geometry a
         * person drew by hand, while pointing it at a real party means they find
         * it in the editor with a name on it that surprises them.
         */
        ->and($mine->fresh()->signer_index)->toBe(0);
});

it('lets the author take a signature back off a template they may no longer edit', function () {
    [$author, $workspace, $template] = templateBeingPrepared();

    $signer = ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
    ]);

    // forceFill, because the columns recording what somebody did are kept out of
    // the fillable list — see StoreSignature, which writes them the same way.
    $signer->forceFill(['signed_at' => now()])->save();

    // The editor is shut, quite rightly: moving a box under a signature would
    // change what was agreed to. See ContractPolicy::update.
    expect($author->can('update', $template->fresh(['signers'])))->toBeFalse();

    actingAs($author)
        ->put(route('chat.contracts.template.sign-along', [$workspace, $template]), [
            'signs_along' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Which is the whole point of this endpoint sitting outside that rule:
    // taking your own signature off is the only way back to an editable
    // template, so it has to survive the lock the signature put on.
    expect($template->fresh(['signers'])->templateSigner())->toBeNull()
        ->and($author->can('update', $template->fresh(['signers'])))->toBeTrue();
});

it('says on the template screen exactly what is still missing', function () {
    [$author, $workspace, $template] = templateBeingPrepared();

    ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
    ]);

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $template]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contract-show')
            ->where('template.isReadyToSend', false)
            ->where('template.signsAlong', true)
            ->where('template.authorSigned', false)

            /*
             * Both reasons at once rather than the first of them. Somebody who
             * has to draw the boxes and then sign should see both from here
             * instead of finding out one refusal at a time.
             */
            ->where('template.blockers', ['fields', 'signature'])

            // Their own way in, which is the ordinary signing page every
            // recipient uses — see Contract::isSignable.
            ->where('template.signUrl', fn (?string $url): bool => $url !== null)
        );
});

it('offers none of the buttons that mean nothing on a template', function () {
    [$author, $workspace, $template] = templateBeingPrepared();

    actingAs($author)
        ->get(route('chat.contracts.show', [$workspace, $template]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            /*
             * A template is a draft forever, so every one of these would
             * otherwise be offered on the strength of the status alone — and the
             * first of them is a form asking for the addresses of people a mould
             * is being posted to.
             */
            ->where('can.send', false)
            ->where('can.remind', false)
            ->where('can.cancel', false)
            ->where('can.duplicate', false)

            // Editing stays: the boxes are the whole reason it exists.
            ->where('can.update', true)
        );
});

it('names a template\'s parties by their place rather than by nobody', function () {
    [$author, $workspace, $template] = templateBeingPrepared(2);

    ContractSigner::factory()->inPosition(0)->create([
        'contract_id' => $template->id,
        'user_id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
    ]);

    actingAs($author)
        ->get(route('chat.contracts.edit', [$workspace, $template]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/contract-edit')
            ->where('contract.isTemplate', true)

            /*
             * Three parties out of a count and one signer row. The people this
             * goes to have no rows here — inventing them would put records in
             * contract_signers that look exactly like people who were asked and
             * never answered — so the editor is handed places instead of names.
             */
            ->has('contract.signers', 3)
            ->where('contract.signers.0', ['index' => 0, 'name' => 'Ikzelf'])
            ->where('contract.signers.1', ['index' => 1, 'name' => 'Ontvanger 1'])
            ->where('contract.signers.2', ['index' => 2, 'name' => 'Ontvanger 2'])
        );
});

it('lets a box be drawn for a party that has no row yet', function () {
    [$author, $workspace, $template] = templateBeingPrepared(3);

    actingAs($author)
        ->put(route('chat.contracts.fields', [$workspace, $template]), [
            'fields' => [[
                'id' => null,
                'page' => 1,
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.2,
                'height' => 0.05,
                'type' => 'signature',
                'label' => 'Handtekening borgsteller',
                'is_required' => true,

                /*
                 * The third recipient, who does not exist as a row and never
                 * will. Bounded by the count rather than by the signer table —
                 * which says nought — so without that the editor could not lay
                 * out a template for more than one party at all.
                 */
                'signer_index' => 2,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($template->fresh(['fields'])->fields->first()->signer_index)->toBe(2);
});
