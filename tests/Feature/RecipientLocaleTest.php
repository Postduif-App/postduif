<?php

use App\Mail\WorkspaceInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ChannelActivity;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * What actually left the building, subject and body.
 *
 * The array mailer keeps every message it was handed, so this reads the real
 * thing after the real notification sender, the real queue job and the real
 * markdown render — the whole path the locale has to survive.
 */
function lastMailSent(): Email
{
    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    /** @var ArrayTransport $transport */
    $message = $transport->messages()->last();

    expect($message)->not->toBeNull('Er is niets verstuurd.');

    /** @var Email $email */
    $email = $message->getOriginalMessage();

    return $email;
}

/** One missed channel, in the shape FindMissedActivity produces. */
function missedChannel(): Collection
{
    return collect([[
        'channelId' => 1,
        'label' => '#klantproject',
        'unread' => 1,
        'mentions' => 0,
        'newestId' => '01JAAAAAAAAAAAAAAAAAAAAAAA',
    ]]);
}

it('writes to a member in the language that member chose, not the one the sender was working in', function () {
    /*
     * The whole reason this test exists. Somebody Dutch said something, so the
     * request that caused this notification ran — and is still running — in
     * Dutch. The member being told about it reads English.
     */
    App::setLocale('nl');

    $reader = User::factory()->create(['locale' => 'en', 'notify_via_mail' => true]);
    $workspace = Workspace::factory()->create(['name' => 'Postduif']);

    $reader->notify(new ChannelActivity($workspace, missedChannel()));

    $mail = lastMailSent();

    expect($mail->getSubject())->toBe('One new message in Postduif')
        ->and($mail->getHtmlBody())
        ->toContain('There was talk in Postduif while you were away.')
        ->and($mail->getHtmlBody())->not->toContain('terwijl je er niet was');

    // And the sender's own language is where it was: switching for a recipient
    // must not leak into whatever the request was still doing.
    expect(App::getLocale())->toBe('nl');
});

it('writes to a Dutch member in Dutch while the application is running in English', function () {
    App::setLocale('en');

    $reader = User::factory()->create(['locale' => 'nl', 'notify_via_mail' => true]);
    $workspace = Workspace::factory()->create(['name' => 'Postduif']);

    $reader->notify(new ChannelActivity($workspace, missedChannel()));

    /*
     * The mirror image, and not a formality: a test that only ever asked for
     * English would also pass if the recipient's preference were ignored and
     * English simply happened to be the default.
     */
    expect(lastMailSent()->getSubject())->toBe('Eén nieuw bericht in Postduif');
});

it('leaves the locale alone for a member who never chose one', function () {
    $undecided = User::factory()->create(['locale' => null]);
    $unsupported = User::factory()->create(['locale' => 'de']);

    /*
     * Null rather than the default, because Laravel reads null as "do not
     * switch". A member who set nothing gets whatever the application is
     * already speaking; a stored language this application no longer has would
     * otherwise fall back key by key into a half-translated mail.
     */
    expect($undecided->preferredLocale())->toBeNull()
        ->and($unsupported->preferredLocale())->toBeNull();
});

it('heads the mails to outsiders in the language of the letter underneath', function () {
    $inviter = User::factory()->create(['name' => 'Sebastiaan']);
    $workspace = Workspace::factory()->create(['name' => 'Postduif']);

    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $inviter->id,
    ]);

    /*
     * An invitation goes to somebody with no account and so no preference —
     * mail.php says as much. What can be got right is that the subject and the
     * body agree: a Dutch subject over an English letter was the one thing
     * these two mails still typed out by hand.
     */
    App::setLocale('en');
    expect((new WorkspaceInvitationMail($invitation))->envelope()->subject)
        ->toBe('Sebastiaan is inviting you to Postduif');

    App::setLocale('nl');
    expect((new WorkspaceInvitationMail($invitation))->envelope()->subject)
        ->toBe('Sebastiaan nodigt je uit voor Postduif');
});
