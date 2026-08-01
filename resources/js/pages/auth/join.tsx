import { Form, Head, Link } from '@inertiajs/react';
import { Hash, Lock } from 'lucide-react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { join } from '@/routes/invite-links';

type State = 'usable' | 'expired' | 'revoked' | 'exhausted' | 'unknown';

/**
 * Only two ways in, unlike a mailed invitation: signed in, or not yet. A link
 * names nobody, so it can never turn out to be meant for somebody else.
 */
type Mode = 'register' | 'accept' | 'none';

interface JoinProps {
    state: State;
    mode: Mode;
    token?: string;
    currentEmail?: string | null;
    passwordRules?: string;
    link: {
        workspaceName: string;
        invitedBy: string | null;
        roleLabel: string;
        isGuest: boolean;
        channels: string[];
    } | null;
}

/**
 * Each reason a link stops working gets its own words. "Ask for a new one" is
 * the same advice every time, but knowing whether it ran out or was withdrawn
 * is what tells somebody whether asking is worth it.
 */
const DEAD_END: Record<string, { title: string; body: string }> = {
    expired: {
        title: 'Deze uitnodigingslink is verlopen',
        body: 'De link was maar een beperkte tijd geldig. Vraag degene die hem stuurde om een nieuwe.',
    },
    revoked: {
        title: 'Deze uitnodigingslink is ingetrokken',
        body: 'De link werkt niet meer omdat iemand hem heeft ingetrokken. Vraag om een nieuwe als je er nog bij moet.',
    },
    exhausted: {
        title: 'Deze uitnodigingslink is opgebruikt',
        body: 'De link mocht een beperkt aantal keer gebruikt worden, en dat aantal is bereikt. Vraag om een nieuwe.',
    },
    unknown: {
        title: 'Deze link werkt niet',
        body: 'Mogelijk is de link onderweg afgekapt. Controleer of je hem in zijn geheel hebt geplakt, of vraag om een nieuwe.',
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

export default function JoinPage({
    state,
    mode,
    token,
    currentEmail,
    passwordRules,
    link,
}: JoinProps) {
    if (state !== 'usable' || link === null || token === undefined) {
        const message = DEAD_END[state] ?? DEAD_END.unknown;

        return (
            <>
                <Head title="Uitnodigingslink" />
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
            <Head title="Uitnodigingslink" />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {link.invitedBy
                            ? `${link.invitedBy} nodigt je uit voor`
                            : 'Je bent uitgenodigd voor'}
                    </p>
                    <p className="text-lg font-medium">{link.workspaceName}</p>
                    {link.isGuest && (
                        <p className="inline-flex items-center gap-1 rounded border border-amber-500/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <Lock className="size-3" />
                            Als gast
                        </p>
                    )}
                </div>

                {link.isGuest && (
                    <p className="text-center text-sm text-muted-foreground">
                        Je ziet alleen de kanalen hieronder. De rest van{' '}
                        {link.workspaceName} blijft buiten beeld.
                    </p>
                )}

                <ChannelList channels={link.channels} />

                <Form
                    {...join.form(token)}
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
                                        {/*
                                            Asked rather than fixed: unlike a
                                            mailed invitation this link was not
                                            addressed to anybody, so nobody
                                            decided in advance who is filling
                                            this in.
                                        */}
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            autoFocus
                                            autoComplete="email"
                                            placeholder="jij@voorbeeld.nl"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Naam</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            type="text"
                                            required
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
                                        <InputError message={errors.password} />
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

                            {mode === 'accept' && currentEmail && (
                                <p className="text-center text-sm text-muted-foreground">
                                    Je bent ingelogd als{' '}
                                    <span className="font-medium text-foreground">
                                        {currentEmail}
                                    </span>
                                    .
                                </p>
                            )}

                            <Button type="submit" className="w-full">
                                {processing && <Spinner />}
                                Deelnemen
                            </Button>

                            {mode === 'register' && (
                                <p className="text-center text-sm text-muted-foreground">
                                    Heb je al een account?{' '}
                                    {/*
                                        The join page put itself down as the
                                        intended URL, so logging in lands back
                                        here — signed in, one button away.
                                    */}
                                    <Link
                                        href={login()}
                                        className="font-medium text-foreground underline underline-offset-4"
                                    >
                                        Log eerst in
                                    </Link>
                                </p>
                            )}
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

JoinPage.layout = {
    title: 'Je bent uitgenodigd',
    description: 'Nog één stap en je zit erin',
};
