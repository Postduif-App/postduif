import { Form, Head, Link } from '@inertiajs/react';
import { Hash, Lock } from 'lucide-react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, logout } from '@/routes';
import { accept } from '@/routes/invitations';

type State = 'pending' | 'expired' | 'accepted' | 'unknown';

/**
 * Which of the ways in this visitor is looking at. Worked out on the server —
 * whether an account already exists for the invited address is not something
 * the browser gets to ask about.
 */
type Mode = 'register' | 'login' | 'accept' | 'mismatch' | 'none';

interface InvitationProps {
    state: State;
    mode: Mode;
    token?: string;
    currentEmail?: string | null;
    passwordRules?: string;
    invitation: {
        email: string;
        workspaceName: string;
        invitedBy: string;
        role: string;
        roleLabel: string;
        isGuest: boolean;
        channels: string[];
    } | null;
}

const DEAD_END: Record<string, { title: string; body: string }> = {
    expired: {
        title: 'Deze uitnodiging is verlopen',
        body: 'Vraag degene die je uitnodigde om een nieuwe link te sturen. Die is daarna weer twee weken geldig.',
    },
    accepted: {
        title: 'Deze uitnodiging is al gebruikt',
        body: 'Er is al een account mee aangemaakt. Log in met het e-mailadres waarop je de uitnodiging kreeg.',
    },
    unknown: {
        title: 'Deze link werkt niet',
        body: 'Mogelijk is de uitnodiging ingetrokken of is de link onderweg afgekapt. Vraag om een nieuwe.',
    },
};

function ChannelList({ channels }: { channels: string[] }) {
    if (channels.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1.5 rounded-lg border p-3">
            <p className="text-xs font-medium text-muted-foreground">
                Je krijgt toegang tot
            </p>
            <ul className="flex flex-wrap gap-1.5">
                {channels.map((name) => (
                    <li
                        key={name}
                        className="flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-sm"
                    >
                        <Hash className="size-3.5 text-muted-foreground" />
                        {name}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function InvitationPage({
    state,
    mode,
    token,
    currentEmail,
    passwordRules,
    invitation,
}: InvitationProps) {
    if (state !== 'pending' || invitation === null || token === undefined) {
        const message = DEAD_END[state] ?? DEAD_END.unknown;

        return (
            <>
                <Head title="Uitnodiging" />
                <div className="space-y-4 text-center">
                    <h2 className="text-lg font-medium">{message.title}</h2>
                    <p className="text-sm text-muted-foreground">
                        {message.body}
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <Link href={login()}>Naar het inlogscherm</Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Uitnodiging" />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {invitation.invitedBy} nodigt je uit voor
                    </p>
                    <p className="text-lg font-medium">
                        {invitation.workspaceName}
                    </p>
                    {invitation.isGuest && (
                        <p className="inline-flex items-center gap-1 rounded border border-amber-500/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <Lock className="size-3" />
                            Als gast
                        </p>
                    )}
                </div>

                {invitation.isGuest && (
                    <p className="text-center text-sm text-muted-foreground">
                        Je ziet alleen de kanalen hieronder. De rest van{' '}
                        {invitation.workspaceName} blijft buiten beeld.
                    </p>
                )}

                <ChannelList channels={invitation.channels} />

                {mode === 'mismatch' ? (
                    <div className="space-y-3 text-center">
                        <p className="text-sm text-muted-foreground">
                            Deze uitnodiging is voor{' '}
                            <span className="font-medium text-foreground">
                                {invitation.email}
                            </span>
                            , maar je bent ingelogd als {currentEmail}. Log uit
                            en open de link opnieuw.
                        </p>
                        <Button asChild variant="outline" className="w-full">
                            <Link href={logout()} as="button">
                                Uitloggen
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <Form
                        {...accept.form(token)}
                        disableWhileProcessing
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                {mode === 'register' && (
                                    <div className="grid gap-6">
                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                E-mailadres
                                            </Label>
                                            {/* Fixed: the invitation was sent
                                                to this address, and it is what
                                                the token vouches for. */}
                                            <Input
                                                id="email"
                                                type="email"
                                                value={invitation.email}
                                                readOnly
                                                disabled
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Naam</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                type="text"
                                                required
                                                autoFocus
                                                autoComplete="name"
                                                placeholder="Voor- en achternaam"
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Wachtwoord
                                            </Label>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                required
                                                autoComplete="new-password"
                                                placeholder="Wachtwoord"
                                                passwordrules={passwordRules}
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                Wachtwoord bevestigen
                                            </Label>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                required
                                                autoComplete="new-password"
                                                placeholder="Wachtwoord bevestigen"
                                                passwordrules={passwordRules}
                                            />
                                            <InputError
                                                message={
                                                    errors.password_confirmation
                                                }
                                            />
                                        </div>
                                    </div>
                                )}

                                {mode === 'login' && (
                                    <p className="text-center text-sm text-muted-foreground">
                                        Er bestaat al een account voor{' '}
                                        <span className="font-medium text-foreground">
                                            {invitation.email}
                                        </span>
                                        . Log in en je staat er meteen in.
                                    </p>
                                )}

                                <Button type="submit" className="w-full">
                                    {processing && <Spinner />}
                                    {mode === 'login'
                                        ? 'Inloggen en deelnemen'
                                        : 'Uitnodiging accepteren'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

InvitationPage.layout = {
    title: 'Je bent uitgenodigd',
    description: 'Nog één stap en je zit erin',
};
