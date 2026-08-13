<?php

use App\Enums\MailTemplateKind;
use App\Enums\SystemRole;
use App\Mail\ContractRequestMail;
use App\Mail\ContractSignedMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Models\WorkspaceMailTemplate;

/**
 * A workspace writing its own contract mails.
 *
 * The bead this covers asks for one thing above all the rest: that the button
 * survives whatever somebody types. Everything else here is text, and text that
 * comes out wrong is embarrassing — but a signing request with no way to sign is
 * a mail that costs the workspace the deal it was sent about.
 *
 * The other half of the suite is the promise that nothing changed for the
 * hundreds of workspaces that will never open this screen. That is why the
 * first test asserts on the platform's own sentences: they now travel through
 * the same template machinery as anybody's custom text, and if that machinery
 * ever mangles them it has to fail here rather than in somebody's inbox.
 *
 * @return array{0: Contract, 1: ContractSigner}
 */
function contractForMailTemplate(array $attributes = [], ?string $authorLocale = 'nl'): array
{
    $author = User::factory()->create(['name' => 'Joris Bakker', 'locale' => $authorLocale]);
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Samenwerkingsovereenkomst 2027',
        ...$attributes,
    ]);

    $signer = ContractSigner::factory()->create([
        'contract_id' => $contract->id,
        'name' => 'Anna de Vries',
        'email' => 'anna@example.test',
    ]);

    $contract->load(['author', 'workspace.mailTemplates']);
    $signer->setRelation('contract', $contract);

    return [$contract, $signer];
}

/** The mail as a mail server would receive it, in the language it goes out in. */
function renderedRequest(ContractSigner $signer): string
{
    $mailable = (new ContractRequestMail($signer))->locale($signer->contract->mailLocale());

    return $mailable->render();
}

it('sends the platform text when the workspace wrote nothing', function (): void {
    [$contract, $signer] = contractForMailTemplate(['expires_at' => now()->addDays(14)]);

    $html = renderedRequest($signer);

    expect($html)
        ->toContain('Er ligt een contract voor je klaar om te tekenen')
        ->toContain('Joris Bakker vraagt je om')
        ->toContain('Samenwerkingsovereenkomst 2027')
        ->toContain('Contract openen en tekenen')
        ->toContain('Deze link is persoonlijk');

    expect((new ContractRequestMail($signer))->locale('nl')->envelope()->subject)
        ->toBe('Joris Bakker vraagt je om Samenwerkingsovereenkomst 2027 te tekenen');
});

it('puts the button where the placeholder stands', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'heading' => 'Even tekenen, {{ondertekenaar}}',
        'body' => "Hallo {{ondertekenaar}},\n\n{{knop}}\n\nDaarna zijn we klaar.",
        'button_label' => 'Ik teken nu',
    ]);

    $contract->load('workspace.mailTemplates');
    $html = renderedRequest($signer);

    expect($html)
        ->toContain('Even tekenen, Anna de Vries')
        ->toContain('Hallo Anna de Vries')
        ->toContain('Ik teken nu')
        ->toContain('Daarna zijn we klaar')
        // Gone with the platform text it belonged to.
        ->not->toContain('Deze link is persoonlijk');

    /*
     * The order is the assertion. A button placed in the middle of somebody's
     * text has to actually land there, or the placeholder is decoration.
     */
    expect(strpos($html, 'Hallo Anna de Vries'))->toBeLessThan(strpos($html, 'Ik teken nu'));
    expect(strpos($html, 'Ik teken nu'))->toBeLessThan(strpos($html, 'Daarna zijn we klaar'));
});

it('keeps the button even when the text never asked for one', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => 'Teken even, dan is het geregeld.',
    ]);

    $contract->load('workspace.mailTemplates');
    $html = renderedRequest($signer);

    expect($html)
        ->toContain('Teken even, dan is het geregeld.')
        // The platform's own label, because the workspace named no other.
        ->toContain('Contract openen en tekenen')
        ->toContain($signer->signUrl());

    expect(strpos($html, 'Teken even'))->toBeLessThan(strpos($html, 'Contract openen en tekenen'));
});

it('drops the sentence a missing deadline was the point of', function (): void {
    [$contract, $signer] = contractForMailTemplate(['expires_at' => null]);

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => "Graag je handtekening.\n\nTekenen kan tot {{vervaldatum}}.\n\n{{knop}}",
    ]);

    $contract->load('workspace.mailTemplates');
    $html = renderedRequest($signer);

    expect($html)
        ->toContain('Graag je handtekening.')
        ->not->toContain('Tekenen kan tot')
        ->not->toContain('{{vervaldatum}}');
});

it('leaves a placeholder nobody wrote code for exactly as typed', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => 'Beste {{ondertekaar}}, graag tekenen. {{knop}}',
    ]);

    $contract->load('workspace.mailTemplates');

    // Visible rather than swallowed: a typo you can see is one you can fix.
    expect(renderedRequest($signer))->toContain('{{ondertekaar}}');
});

it('will not let a workspace put HTML in somebody else\'s inbox', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => '<a href="https://elders.test">Teken hier</a> en <script>alert(1)</script>. {{knop}}',
    ]);

    $contract->load('workspace.mailTemplates');
    $html = renderedRequest($signer);

    /*
     * The assertion is about tags, not about the address. The link somebody
     * typed still shows up in the mail — as the characters they typed, sitting
     * in a paragraph — and that is the intended outcome. What must not exist is
     * an <a> or a <script> the mail client would act on.
     */
    expect($html)
        ->not->toContain('<script>')
        ->not->toContain('<a href="https://elders.test"')
        // Still readable, just as text rather than as a second button.
        ->toContain('Teken hier');
});

it('reads the language off the sender rather than off whoever is looking', function (): void {
    [$contract, $signer] = contractForMailTemplate(authorLocale: 'en');

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => 'De Nederlandse tekst. {{knop}}',
    ]);

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'en',
        'body' => 'The English text. {{button}}',
    ]);

    $contract->load('workspace.mailTemplates');

    app()->setLocale('nl');

    expect(renderedRequest($signer))
        ->toContain('The English text.')
        ->not->toContain('De Nederlandse tekst.');
});

it('understands a Dutch placeholder in a mail that goes out in English', function (): void {
    [$contract, $signer] = contractForMailTemplate(authorLocale: 'en');

    /*
     * The case the aliases exist for. Somebody wrote this text while their own
     * screen was in Dutch, then filled in the English tab by pasting it — and
     * the words between the braces are still Dutch.
     */
    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'en',
        'body' => 'Dear {{ondertekenaar}}, please sign {{titel}}. {{knop}}',
    ]);

    $contract->load('workspace.mailTemplates');
    $html = renderedRequest($signer);

    expect($html)
        ->toContain('Dear Anna de Vries')
        ->toContain('Samenwerkingsovereenkomst 2027')
        ->toContain('Open the contract and sign');
});

it('falls back to our text for a language the workspace never filled in', function (): void {
    [$contract, $signer] = contractForMailTemplate(authorLocale: 'en');

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'body' => 'Alleen in het Nederlands geschreven. {{knop}}',
    ]);

    $contract->load('workspace.mailTemplates');

    expect(renderedRequest($signer))
        ->toContain('There is a contract waiting for your signature')
        ->not->toContain('Alleen in het Nederlands geschreven.');
});

it('lets a workspace rewrite the mail that carries the signed document', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    $signer->forceFill(['signed_at' => now()])->save();
    $signer->setRelation('contract', $contract);

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractSigned,
        'locale' => 'nl',
        'subject' => 'Getekend: {{titel}}',
        'heading' => 'Dank je wel, {{ondertekenaar}}',
        'body' => "De PDF zit erbij.\n\n{{knop}}",
        'button_label' => 'Downloaden',
    ]);

    $contract->load('workspace.mailTemplates');

    $mailable = (new ContractSignedMail($signer))->locale('nl');

    expect($mailable->envelope()->subject)->toBe('Getekend: Samenwerkingsovereenkomst 2027');
    expect($mailable->render())
        ->toContain('Dank je wel, Anna de Vries')
        ->toContain('De PDF zit erbij.')
        ->toContain('Downloaden')
        ->not->toContain('Hier is het ondertekende document');
});

it('does not let the request text leak into the signed-document mail', function (): void {
    [$contract, $signer] = contractForMailTemplate();

    $signer->forceFill(['signed_at' => now()])->save();
    $signer->setRelation('contract', $contract);

    /*
     * Two rows for one workspace, differing only in kind. Worth its own test:
     * the lookup takes three columns and getting one of them wrong would show
     * up as a signing request arriving after somebody already signed.
     */
    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $contract->workspace_id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'heading' => 'Wil je even tekenen',
    ]);

    $contract->load('workspace.mailTemplates');

    expect((new ContractSignedMail($signer))->locale('nl')->render())
        ->toContain('Hier is het ondertekende document')
        ->not->toContain('Wil je even tekenen');
});

it('quotes a multi-line note as one block instead of letting it escape', function (): void {
    [$contract, $signer] = contractForMailTemplate([
        'message' => "Regel een.\nRegel twee.",
    ]);

    // The platform's own text puts the note behind a "> ", which is the case
    // the continuation rule in RenderMailTemplate exists for.
    $html = renderedRequest($signer);

    expect($html)->toContain('Regel een.')->toContain('Regel twee.');
    expect(substr_count($html, '<blockquote'))->toBe(1);
});
