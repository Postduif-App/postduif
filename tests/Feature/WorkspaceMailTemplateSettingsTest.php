<?php

use App\Enums\MailTemplateKind;
use App\Enums\SystemRole;
use App\Models\User;
use App\Models\WorkspaceMailTemplate;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * The screen a workspace rewrites its contract mails on.
 *
 * What is worth testing here is not that a form saves. It is the three things
 * that are easy to get subtly wrong and impossible to notice: that an emptied
 * field means "use ours" rather than "say nothing", that a placeholder the
 * chosen mail cannot fill is refused where somebody can still see it, and that
 * this whole screen is closed to anybody who may not run the workspace.
 */
function adminOfWorkspace(): array
{
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    return [$admin, $workspace];
}

it('shows our own text beside whatever the workspace wrote', function (): void {
    [$admin, $workspace] = adminOfWorkspace();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $workspace->id,
        'kind' => MailTemplateKind::ContractRequest,
        'locale' => 'nl',
        'heading' => 'Even tekenen',
    ]);

    actingAs($admin)
        ->get(route('workspace.mail-texts.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/workspace-mail-templates')
            ->where('templates.contract_request.nl.heading', 'Even tekenen')
            // Never written, so still ours — and said out loud to the screen.
            ->where('templates.contract_request.nl.body', null)
            ->where('defaults.contract_request.nl.heading', 'Er ligt een contract voor je klaar om te tekenen')
            ->where('defaults.contract_request.en.heading', 'There is a contract waiting for your signature')
            ->has('kinds', 2)
            ->has('locales', 2)
        );
});

it('keeps a workspace out of the screen when it may not run the workspace', function (): void {
    [, $workspace] = adminOfWorkspace();

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);

    actingAs($member)->get(route('workspace.mail-texts.edit'))->assertForbidden();
    actingAs($member)->patch(route('workspace.mail-texts.update'), ['templates' => []])->assertForbidden();
});

it('stores what was typed and forgets what was emptied', function (): void {
    [$admin, $workspace] = adminOfWorkspace();

    WorkspaceMailTemplate::factory()->create([
        'workspace_id' => $workspace->id,
        'kind' => MailTemplateKind::ContractSigned,
        'locale' => 'nl',
        'heading' => 'Weg hiermee',
    ]);

    actingAs($admin)
        ->patch(route('workspace.mail-texts.update'), [
            'templates' => [
                [
                    'kind' => MailTemplateKind::ContractRequest->value,
                    'locale' => 'nl',
                    'subject' => 'Graag je handtekening',
                    'heading' => '',
                    'body' => "Hallo {{ondertekenaar}}.\n\n{{knop}}",
                    'button_label' => null,
                ],
                [
                    // Everything cleared: the row should go rather than linger.
                    'kind' => MailTemplateKind::ContractSigned->value,
                    'locale' => 'nl',
                    'subject' => '',
                    'heading' => '',
                    'body' => '',
                    'button_label' => '',
                ],
            ],
        ])
        ->assertRedirect();

    $request = $workspace->mailTemplates()
        ->for(MailTemplateKind::ContractRequest, 'nl')
        ->firstOrFail();

    expect($request->subject)->toBe('Graag je handtekening')
        // Blank arrived, null was stored: the difference between "no heading"
        // and "use theirs".
        ->and($request->heading)->toBeNull()
        ->and($request->body)->toContain('{{ondertekenaar}}');

    expect($workspace->mailTemplates()->for(MailTemplateKind::ContractSigned, 'nl')->exists())
        ->toBeFalse();
});

it('refuses a placeholder the chosen mail could never fill in', function (): void {
    [$admin, $workspace] = adminOfWorkspace();

    actingAs($admin)
        ->patch(route('workspace.mail-texts.update'), [
            'templates' => [[
                'kind' => MailTemplateKind::ContractSigned->value,
                'locale' => 'nl',
                // There is no deadline left on a document that has been signed.
                'body' => 'Tekenen kon tot {{vervaldatum}}.',
            ]],
        ])
        ->assertSessionHasErrors('templates.0.body');

    expect($workspace->mailTemplates()->count())->toBe(0);
});

it('previews text that has not been saved', function (): void {
    [$admin] = adminOfWorkspace();

    $response = actingAs($admin)
        ->postJson(route('workspace.mail-texts.preview'), [
            'kind' => MailTemplateKind::ContractRequest->value,
            'locale' => 'nl',
            'subject' => 'Onderwerp met {{titel}}',
            'body' => "Beste {{ondertekenaar}},\n\n{{knop}}\n\nTot slot.",
            'button_label' => 'Tekenen maar',
        ])
        ->assertOk();

    expect($response->json('subject'))->toContain('Samenwerkingsovereenkomst 2027');
    expect($response->json('html'))
        ->toContain('Anna de Vries')
        ->toContain('Tekenen maar')
        ->toContain('Tot slot.');
});

it('does not let a preview smuggle markup past the renderer', function (): void {
    [$admin] = adminOfWorkspace();

    $response = actingAs($admin)
        ->postJson(route('workspace.mail-texts.preview'), [
            'kind' => MailTemplateKind::ContractRequest->value,
            'locale' => 'nl',
            'body' => '<script>alert(1)</script> {{knop}}',
        ])
        ->assertOk();

    expect($response->json('html'))->not->toContain('<script>');
});
