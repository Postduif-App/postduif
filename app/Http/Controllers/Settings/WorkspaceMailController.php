<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Mail\SendTestMail;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\MailTransport;
use App\Enums\SmtpEncryption;
use App\Features\Tickets as TicketsFeature;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\WorkspaceMailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where this workspace's mail leaves from.
 *
 * Its own screen rather than a block on the general page, for the same reason
 * the theme and the permissions each got one: this is a thing you sit down to
 * with an account at somebody else's company open in the other tab, not a field
 * you flick past on the way to renaming the workspace.
 *
 * Nothing here ever sends a secret back to the browser. The screen is told
 * whether a password exists, never what it is — see edit() — and a blank field
 * on save means "leave it alone" rather than "clear it", which is the only
 * behaviour that lets somebody change the port without retyping the password.
 */
class WorkspaceMailController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        /*
         * firstOrNew rather than first, so the rest of this method has one
         * shape to describe instead of two. A workspace that never opened this
         * screen has no row, and an unsaved model already answers every
         * question below the way that workspace should — see the defaults on
         * WorkspaceMailSettings. Nothing is saved here; this one is thrown away
         * with the response.
         *
         * Through the relation query rather than the property either way: lazy
         * loading is an exception outside production, and there is nothing to
         * eager-load from — the workspace came from the member, not a binding.
         */
        $settings = $workspace->mailSettings()->firstOrNew();

        return Inertia::render('settings/workspace-mail', [
            'workspace' => ['name' => $workspace->name],
            'settings' => [
                'transport' => $settings->transport->value,
                'from_address' => $settings->from_address,
                'from_name' => $settings->from_name,
                'smtp_host' => $settings->smtp_host,
                'smtp_port' => $settings->smtp_port,
                // The only field with a sensible answer before anybody chooses:
                // STARTTLS on 587 is what all but a handful of providers want.
                'smtp_encryption' => ($settings->smtp_encryption ?? SmtpEncryption::StartTls)->value,
                'smtp_username' => $settings->smtp_username,
                'postmark_message_stream' => $settings->postmark_message_stream,
                'lettermint_route_id' => $settings->lettermint_route_id,
                /*
                 * The one thing the screen may know about a secret: that there
                 * is one. Enough to render "•••••• — laat leeg om te bewaren"
                 * instead of an empty field that looks like nothing was ever
                 * set, and not enough to be worth intercepting.
                 */
                'has_smtp_password' => filled($settings->smtp_password),
                'has_postmark_token' => filled($settings->postmark_token),
                'has_lettermint_token' => filled($settings->lettermint_token),
                'verified_at' => $settings->verified_at?->toIso8601String(),
                'last_error' => $settings->last_error,

                /*
                 * The other direction. Not the token itself: it is shown once,
                 * when it is made, and after that the screen only knows whether
                 * there is one — the same rule the three secrets above follow.
                 * The whole URL is sent rather than the path, because what
                 * somebody does with it is paste it into a provider's
                 * dashboard, and half an address is not pasteable.
                 */
                'has_inbound_token' => filled($settings->inbound_token),
                'inbound_url' => $settings->inbound_token === null
                    ? null
                    : route('mail.inbound', ['token' => str_repeat('•', 16)]),
                'inbound_channel_id' => $settings->inbound_channel_id,
                'inbound_address' => $settings->inbound_address,
            ],

            /*
             * Channels that can actually hold a ticket, for the picker. A
             * channel that keeps none would accept the setting and then drop
             * every mail sent to it — see ReceiveInboundEmail, which checks the
             * same thing again at delivery time because a channel can stop
             * keeping tickets long after this was chosen.
             */
            'inboundChannels' => $workspace->hasFeature(TicketsFeature::class)
                ? $workspace->channels()
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->get()
                    ->filter(fn (Channel $channel): bool => $channel->hasTickets())
                    ->map(fn (Channel $channel): array => [
                        'id' => $channel->id,
                        'name' => $channel->name,
                    ])
                    ->values()
                    ->all()
                : [],
            // Where the test message would go, said out loud on the button:
            // this is the address of whoever is looking, and nobody should have
            // to press it to find that out.
            'testRecipient' => $request->user()->email,
            'transportOptions' => collect(MailTransport::cases())
                ->map(fn (MailTransport $transport): array => [
                    'value' => $transport->value,
                    'label' => $transport->label(),
                    'description' => $transport->description(),
                ])->all(),
            'encryptionOptions' => collect(SmtpEncryption::cases())
                ->map(fn (SmtpEncryption $encryption): array => [
                    'value' => $encryption->value,
                    'label' => $encryption->label(),
                    'description' => $encryption->description(),
                    'port' => $encryption->defaultPort(),
                ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $settings = $workspace->mailSettings()->firstOrNew();

        /*
         * Read before validating, and unvalidated on purpose: which rules apply
         * depends on which transport was picked, so the value has to be in hand
         * to build them. Anything that is not a case falls back to Default,
         * which asks for nothing — and the Enum rule below still refuses the
         * request, so a nonsense transport is a 422 rather than a silent save.
         */
        $transport = MailTransport::tryFrom((string) $request->input('transport'))
            ?? MailTransport::Default;

        $this->keepStoredSecrets($request, $settings);

        $validated = $request->validate([
            'transport' => ['required', new Enum(MailTransport::class)],
            /*
             * An address without a working transport behind it is the fastest
             * way to have every message land in spam, so this is only offered
             * once a transport has been chosen — and required once it has, because
             * an API key for one domain sending as another is the same problem
             * from the other side.
             */
            'from_address' => [$transport->isConfigurable() ? 'required' : 'nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:60'],

            'smtp_host' => ['required_if:transport,smtp', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['required_if:transport,smtp', 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required_if:transport,smtp', 'nullable', new Enum(SmtpEncryption::class)],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],

            'postmark_token' => ['required_if:transport,postmark', 'nullable', 'string', 'max:255'],
            'postmark_message_stream' => ['nullable', 'string', 'max:255'],

            'lettermint_token' => ['required_if:transport,lettermint', 'nullable', 'string', 'max:255'],
            'lettermint_route_id' => ['nullable', 'string', 'max:255'],
        ]);

        $settings->fill([
            ...Arr::only($validated, ['transport', 'from_address', 'from_name']),
            ...Arr::only($validated, $transport->fields()),
        ]);

        /*
         * Everything the chosen transport does not read is cleared rather than
         * left behind. Somebody who moves from SMTP to Postmark has stopped
         * using that password and is entitled to expect it gone — a credential
         * that survives the screen saying it is no longer in use is a
         * credential nobody knows they still have.
         */
        foreach (array_diff(MailTransport::allFields(), $transport->fields()) as $field) {
            $settings->{$field} = null;
        }

        /*
         * A change to how mail is sent un-answers the question the tick
         * answered. Checked against the dirty attributes rather than set
         * unconditionally, so renaming the sender does not throw away a
         * verification that is still true of the transport.
         */
        if ($settings->isDirty([...MailTransport::allFields(), 'transport'])) {
            $settings->forceFill(['verified_at' => null, 'last_error' => null]);
        }

        $workspace->mailSettings()->save($settings);

        return back()->with('status', __('flashes.settings.mail_saved'));
    }

    /**
     * Where mail sent to this workspace lands, and the secret that lets it in.
     *
     * Its own endpoint rather than more fields on update(). That one is a form
     * about a transport, where every field is read together and a change to any
     * of them un-answers the verification tick; this is a standing arrangement
     * with a mail provider, and saving a channel here has nothing to say about
     * whether sending still works.
     *
     * The token is minted on the first save and shown once. Re-saving the
     * channel does not touch it — rolling it is the button below, deliberately
     * separate, because it breaks a working delivery until somebody pastes the
     * new URL at the provider.
     */
    public function inbound(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $validated = $request->validate([
            /*
             * Checked against this workspace's own channels, not just against
             * the table. Without the scope, a beheerder could point their
             * letterbox at a channel in somebody else's workspace and have
             * their customers' mail delivered there.
             */
            'inbound_channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
            'inbound_address' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = $workspace->mailSettings()->firstOrNew();

        $settings->fill($validated);

        // Nothing arrives without one, so making it here saves a beheerder a
        // second button they would have to know to press.
        if ($settings->inbound_channel_id !== null && $settings->inbound_token === null) {
            $settings->regenerateInboundToken();
        }

        $workspace->mailSettings()->save($settings);

        return back()->with('status', __('flashes.settings.mail_saved'));
    }

    /**
     * A new secret for the delivery URL, and the old one dead.
     *
     * Shown once, in the flash, because that is the only moment it exists
     * anywhere a person can copy it from — the screen it returns to only ever
     * learns that there is a token, never which.
     */
    public function regenerateInbound(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $settings = $workspace->mailSettings()->firstOrNew();

        $token = $settings->regenerateInboundToken();

        $workspace->mailSettings()->save($settings);

        Inertia::flash('inboundUrl', route('mail.inbound', ['token' => $token]));

        return back();
    }

    /**
     * Send one message through what was just saved.
     *
     * Against the stored row rather than the form, so what is tested is what
     * will actually be used — including the secrets the screen never saw. That
     * is why the button saves first on the front end: testing a form somebody
     * has edited but not saved would answer a question about settings that do
     * not exist.
     */
    public function test(Request $request, SendTestMail $sendTestMail): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $settings = $workspace->mailSettings()->first();

        if ($settings === null || ! $settings->isUsable()) {
            return back()->withErrors([
                'transport' => __('settings.mail.test_unconfigured'),
            ]);
        }

        $failure = $sendTestMail->handle($settings, $request->user()->email);

        if ($failure !== null) {
            return back()->withErrors(['transport' => $failure]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.settings.mail_test_sent', ['email' => $request->user()->email]),
        ]);

        return back();
    }

    /**
     * Treat a blank secret as "keep the one you have".
     *
     * The screen cannot show a password it was never sent, so an untouched
     * field arrives empty — and without this, saving a changed port would wipe
     * the credential that port was for. Only ever fills in from the stored row,
     * so it cannot invent a value the workspace never set.
     */
    private function keepStoredSecrets(Request $request, WorkspaceMailSettings $settings): void
    {
        foreach (['smtp_password', 'postmark_token', 'lettermint_token'] as $secret) {
            if (blank($request->input($secret)) && filled($settings->{$secret})) {
                $request->merge([$secret => $settings->{$secret}]);
            }
        }
    }
}
