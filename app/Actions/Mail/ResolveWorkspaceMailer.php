<?php

namespace App\Actions\Mail;

use App\Models\Workspace;
use App\Models\WorkspaceMailSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Mail;

/**
 * Which mailer a workspace's mail goes out on.
 *
 * The single place that turns a settings row into something the mail system
 * will accept, and the reason the rest of the application only ever has to say
 * Mail::mailer($this->resolve->handle($workspace)).
 *
 * It hands back a *name* rather than a Mailer, which is the whole trick. A
 * notification cannot be given a built mailer — MailMessage::mailer() takes a
 * string — so anything that returned an object would work for the two mailables
 * and leave the notifications behind. Registering the config under a name and
 * returning the name covers both with one mechanism.
 *
 * Null means "the application's own", and every caller passes that straight to
 * Mail::mailer(), which reads null as the default. That is deliberate: the
 * fallback costs nothing at the call sites and so cannot be forgotten at one of
 * them.
 */
class ResolveWorkspaceMailer
{
    public function __construct(private Repository $config) {}

    public function handle(Workspace $workspace): ?string
    {
        /*
         * loadMissing rather than reading the relation straight off: lazy
         * loading is an exception everywhere but production, and this runs from
         * call sites that were handed a workspace by a route binding and had no
         * reason to eager-load anything. Asking for it explicitly is the only
         * honest version — the query happens either way, and this one does not
         * repeat itself when a caller sends to twenty recipients in a loop.
         */
        $settings = $workspace->loadMissing('mailSettings')->mailSettings;

        if ($settings === null) {
            return null;
        }

        return $this->register($settings, $workspace->id);
    }

    /**
     * The same, for somewhere that is already holding the settings row.
     *
     * What the test-send button needs. Going back through the workspace there
     * would re-read the row that was just written — and, worse, could read a
     * relation loaded before the write and test the previous credentials.
     */
    public function forSettings(WorkspaceMailSettings $settings): ?string
    {
        return $this->register($settings, $settings->workspace_id);
    }

    /**
     * Put the transport in the config under a name of its own and hand that
     * name back.
     *
     * Named per workspace rather than reusing one slot, because a queue worker
     * is long-lived and handles jobs for every workspace in turn: one shared
     * name would leave the mailer resolved for the previous job cached under
     * it, and a workspace would send through somebody else's credentials. That
     * is also why the purge is unconditional — the config can be rewritten
     * freely, but MailManager keeps the built Mailer under the same key until
     * told otherwise, and a password changed on this screen would otherwise go
     * on using the old one for the life of the worker.
     */
    private function register(WorkspaceMailSettings $settings, int $workspaceId): ?string
    {
        $mailerConfig = $settings->mailerConfig();

        if ($mailerConfig === null) {
            return null;
        }

        $name = 'workspace-'.$workspaceId;

        $this->config->set('mail.mailers.'.$name, $mailerConfig);
        Mail::purge($name);

        return $name;
    }
}
