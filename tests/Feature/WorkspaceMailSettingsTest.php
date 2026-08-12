<?php

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Actions\Workspace\InviteToWorkspace;
use App\Enums\MailTransport;
use App\Enums\SmtpEncryption;
use App\Enums\SystemRole;
use App\Mail\MailSettingsTestMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A workspace, somebody who may change its settings, and nothing configured.
 *
 * @return array{0: User, 1: Workspace}
 */
function mailAdmin(): array
{
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    return [$admin, $workspace];
}

it('leaves a workspace on the application mailer until it says otherwise', function () {
    [, $workspace] = mailAdmin();

    expect(app(ResolveWorkspaceMailer::class)->handle($workspace))->toBeNull();
});

it('treats a row that chose nothing the same as no row at all', function () {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->create();

    expect(app(ResolveWorkspaceMailer::class)->handle($workspace->fresh()))->toBeNull();
});

it('builds a mailer of its own once a transport is filled in', function () {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        'smtp_host' => 'smtp.example.test',
        'smtp_port' => 587,
        'smtp_encryption' => SmtpEncryption::StartTls,
        'smtp_username' => 'postbode',
        'smtp_password' => 'geheim',
    ]);

    $name = app(ResolveWorkspaceMailer::class)->handle($workspace->fresh());

    expect($name)->toBe('workspace-'.$workspace->id)
        ->and(config('mail.mailers.'.$name))->toMatchArray([
            'transport' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'postbode',
            'password' => 'geheim',
            'scheme' => 'smtp',
            'auto_tls' => true,
        ]);
});

it('names each workspace its own mailer, so a worker cannot mix two up', function () {
    [, $first] = mailAdmin();
    [, $second] = mailAdmin();

    WorkspaceMailSettings::factory()->for($first)->postmark()->create(['postmark_token' => 'eerste']);
    WorkspaceMailSettings::factory()->for($second)->postmark()->create(['postmark_token' => 'tweede']);

    $resolve = app(ResolveWorkspaceMailer::class);

    expect($resolve->handle($first->fresh()))->not->toBe($resolve->handle($second->fresh()))
        ->and(config('mail.mailers.workspace-'.$first->id.'.token'))->toBe('eerste')
        ->and(config('mail.mailers.workspace-'.$second->id.'.token'))->toBe('tweede');
});

it('turns implicit TLS into the scheme that means it', function () {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        'smtp_port' => 465,
        'smtp_encryption' => SmtpEncryption::Tls,
    ]);

    $name = app(ResolveWorkspaceMailer::class)->handle($workspace->fresh());

    expect(config('mail.mailers.'.$name.'.scheme'))->toBe('smtps');
});

/*
 * Config alone proves nothing. Every assertion above stops at the array that
 * would be handed to the mail system, and an array can be perfectly shaped for
 * a transport that cannot be constructed — which is exactly what happened:
 * Postmark speaks HTTP, and the bridge that carries it does not pull in an HTTP
 * client of its own. These three go the last step and build the thing.
 */
it('really builds the transport each provider needs', function (string $state, string $expected) {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->{$state}()->create();

    $name = app(ResolveWorkspaceMailer::class)->handle($workspace->fresh());

    expect((string) Mail::mailer($name)->getSymfonyTransport())->toContain($expected);
})->with([
    'smtp' => ['smtp', 'smtp'],
    'postmark' => ['postmark', 'postmark'],
    'lettermint' => ['lettermint', 'lettermint'],
]);

it('refuses to build a transport that is missing what it needs', function () {
    [, $workspace] = mailAdmin();
    // A transport was chosen and the credential never arrived — a seeder, an
    // aborted save, a column added later. Falling back beats sending nothing.
    WorkspaceMailSettings::factory()->for($workspace)->create([
        'transport' => MailTransport::Postmark,
    ]);

    expect(app(ResolveWorkspaceMailer::class)->handle($workspace->fresh()))->toBeNull();
});

it('carries the workspace sender onto its own mailer', function () {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->postmark()
        ->from('team@voorbeeld.test', 'Team Voorbeeld')
        ->create();

    $name = app(ResolveWorkspaceMailer::class)->handle($workspace->fresh());

    expect(config('mail.mailers.'.$name.'.from'))
        ->toBe(['address' => 'team@voorbeeld.test', 'name' => 'Team Voorbeeld']);
});

it('keeps the credentials out of the database in the clear', function () {
    [, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        'smtp_password' => 'niet-in-platte-tekst',
    ]);

    $stored = DB::table('workspace_mail_settings')->where('workspace_id', $workspace->id)->value('smtp_password');

    expect($stored)->not->toBe('niet-in-platte-tekst')
        ->and($workspace->mailSettings()->first()->smtp_password)->toBe('niet-in-platte-tekst');
});

it('sends an invitation through the workspace mailer', function () {
    Mail::fake();

    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->postmark()->create();

    app(InviteToWorkspace::class)->handle(
        $workspace->fresh(),
        $admin,
        'nieuw@voorbeeld.test',
        Role::query()->where('workspace_id', $workspace->id)->firstOrFail(),
    );

    Mail::assertSent(
        WorkspaceInvitationMail::class,
        fn (WorkspaceInvitationMail $mail): bool => $mail->mailer === 'workspace-'.$workspace->id,
    );
});

it('sends an invitation through the application mailer when nothing is set', function () {
    Mail::fake();

    [$admin, $workspace] = mailAdmin();

    app(InviteToWorkspace::class)->handle(
        $workspace,
        $admin,
        'nieuw@voorbeeld.test',
        Role::query()->where('workspace_id', $workspace->id)->firstOrFail(),
    );

    Mail::assertSent(
        WorkspaceInvitationMail::class,
        fn (WorkspaceInvitationMail $mail): bool => $mail->mailer === null,
    );
});

it('shows the screen without ever handing a secret to the browser', function () {
    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        'smtp_password' => 'streng-geheim',
    ]);

    $response = $this->actingAs($admin)->get(route('workspace.mail.edit'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('streng-geheim');

    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace-mail')
        ->where('settings.has_smtp_password', true)
        ->missing('settings.smtp_password'));
});

it('keeps somebody who may not manage the workspace off the screen', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member, SystemRole::Member);

    $this->actingAs($member)->get(route('workspace.mail.edit'))->assertForbidden();
    $this->actingAs($member)->patch(route('workspace.mail.update'), [
        'transport' => 'default',
    ])->assertForbidden();

    expect($workspace->mailSettings()->exists())->toBeFalse();
});

it('saves an SMTP server', function () {
    [$admin, $workspace] = mailAdmin();

    $this->actingAs($admin)->patch(route('workspace.mail.update'), [
        'transport' => 'smtp',
        'from_address' => 'team@voorbeeld.test',
        'from_name' => 'Team',
        'smtp_host' => 'smtp.voorbeeld.test',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'postbode',
        'smtp_password' => 'geheim',
    ])->assertRedirect();

    $settings = $workspace->mailSettings()->firstOrFail();

    expect($settings->transport)->toBe(MailTransport::Smtp)
        ->and($settings->smtp_host)->toBe('smtp.voorbeeld.test')
        ->and($settings->smtp_password)->toBe('geheim')
        ->and($settings->from_address)->toBe('team@voorbeeld.test');
});

it('asks for what the chosen transport needs and nothing else', function () {
    [$admin] = mailAdmin();

    $this->actingAs($admin)
        ->patch(route('workspace.mail.update'), ['transport' => 'smtp'])
        ->assertSessionHasErrors(['smtp_host', 'smtp_port', 'from_address'])
        // The other transports' credentials are not this transport's business.
        ->assertSessionDoesntHaveErrors(['postmark_token', 'lettermint_token']);
});

it('keeps a stored secret when the field comes back empty', function () {
    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->postmark()->create([
        'postmark_token' => 'blijft-staan',
        'from_address' => 'team@voorbeeld.test',
    ]);

    $this->actingAs($admin)->patch(route('workspace.mail.update'), [
        'transport' => 'postmark',
        'from_address' => 'anders@voorbeeld.test',
        'postmark_token' => '',
    ])->assertSessionHasNoErrors();

    $settings = $workspace->mailSettings()->firstOrFail();

    expect($settings->postmark_token)->toBe('blijft-staan')
        ->and($settings->from_address)->toBe('anders@voorbeeld.test');
});

it('clears the credentials of a transport that is no longer chosen', function () {
    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        'from_address' => 'team@voorbeeld.test',
        'verified_at' => now(),
    ]);

    $this->actingAs($admin)->patch(route('workspace.mail.update'), [
        'transport' => 'postmark',
        'from_address' => 'team@voorbeeld.test',
        'postmark_token' => 'nieuw-token',
    ])->assertSessionHasNoErrors();

    $settings = $workspace->mailSettings()->firstOrFail();

    expect($settings->smtp_host)->toBeNull()
        ->and($settings->smtp_password)->toBeNull()
        ->and($settings->postmark_token)->toBe('nieuw-token')
        // The tick belonged to the old transport and says nothing about this one.
        ->and($settings->verified_at)->toBeNull();
});

it('goes back to the application mailer when the workspace picks default', function () {
    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->lettermint()->create([
        'from_address' => 'team@voorbeeld.test',
    ]);

    $this->actingAs($admin)
        ->patch(route('workspace.mail.update'), ['transport' => 'default'])
        ->assertSessionHasNoErrors();

    $settings = $workspace->mailSettings()->firstOrFail();

    expect($settings->transport)->toBe(MailTransport::Default)
        ->and($settings->lettermint_token)->toBeNull()
        ->and(app(ResolveWorkspaceMailer::class)->handle($workspace->fresh()))->toBeNull();
});

it('sends a test message to whoever pressed the button', function () {
    Mail::fake();

    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->postmark()->create();

    $this->actingAs($admin)
        ->post(route('workspace.mail.test'))
        ->assertSessionHasNoErrors();

    Mail::assertSent(
        MailSettingsTestMail::class,
        fn (MailSettingsTestMail $mail): bool => $mail->hasTo($admin->email)
            && $mail->mailer === 'workspace-'.$workspace->id,
    );

    expect($workspace->mailSettings()->firstOrFail()->verified_at)->not->toBeNull();
});

it('reports back what the mail server said when a test fails', function () {
    [$admin, $workspace] = mailAdmin();
    WorkspaceMailSettings::factory()->for($workspace)->smtp()->create([
        // Nothing is listening here, so the transport fails on its own terms
        // rather than on a mock's.
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1,
        'smtp_encryption' => SmtpEncryption::None,
    ]);

    $this->actingAs($admin)
        ->post(route('workspace.mail.test'))
        ->assertSessionHasErrors('transport');

    expect($workspace->mailSettings()->firstOrFail()->last_error)->not->toBeNull();
});

it('refuses to test a workspace that has not configured anything', function () {
    Mail::fake();

    [$admin] = mailAdmin();

    $this->actingAs($admin)
        ->post(route('workspace.mail.test'))
        ->assertSessionHasErrors('transport');

    Mail::assertNothingSent();
});
