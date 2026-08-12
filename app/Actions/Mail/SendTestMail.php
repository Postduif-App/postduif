<?php

namespace App\Actions\Mail;

use App\Mail\MailSettingsTestMail;
use App\Models\WorkspaceMailSettings;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one message through a workspace's own settings and report back.
 *
 * The only thing on this screen that finds out anything true. Everything else —
 * a filled-in host, a token of the right length — is a guess; a transport
 * either accepts a message or it does not, and this is what asks it.
 *
 * Catching Throwable rather than a mailer exception, and on purpose. What comes
 * back from a wrong password, an unreachable host, a rejected API key and a DNS
 * name that does not resolve are four different classes from three different
 * libraries, and there is no version of this screen where one of them should be
 * a 500 instead of a red line under the form.
 */
class SendTestMail
{
    public function __construct(private ResolveWorkspaceMailer $resolveMailer) {}

    /**
     * @param  string  $to  Where to send it. The address of whoever pressed the
     *                      button, never one they typed: a form that sends mail
     *                      to an arbitrary address on somebody else's
     *                      credentials is a form somebody else's spam goes out
     *                      of.
     * @return string|null The failure, in the words the transport used, or null
     *                     when it arrived. Passed through rather than
     *                     translated — "Connection refused" and "535
     *                     Authentication failed" are what a mail server said,
     *                     and rewriting them would take away the one clue that
     *                     is worth anything.
     */
    public function handle(WorkspaceMailSettings $settings, string $to): ?string
    {
        try {
            Mail::mailer($this->resolveMailer->forSettings($settings))
                ->to($to)
                ->send(new MailSettingsTestMail($settings->loadMissing('workspace')->workspace));
        } catch (Throwable $failure) {
            $message = $failure->getMessage();

            $settings->markFailed($message);

            return $message;
        }

        $settings->markVerified();

        return null;
    }
}
