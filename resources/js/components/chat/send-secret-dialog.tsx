import { router } from '@inertiajs/react';
import { Check, Copy, KeyRound, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslate } from '@/hooks/use-translate';
import { linkWithKey, sealSecret } from '@/lib/secret-crypto';
import { store, storeStandalone } from '@/routes/chat/sent-secrets';

const DAYS = [1, 3, 7, 14, 30];

/**
 * Handing a secret to one person, readable once.
 *
 * The mirror of SecretRequestDialog, and the one thing that makes it different
 * is worth stating plainly: the secret is encrypted here, in this component,
 * before anything is posted. What goes over the wire is ciphertext and a nonce.
 * The key stays in this browser and is written into the link shown at the end.
 *
 * Which is why this dialog has two faces. The first is the form; the second
 * shows the finished link and is the only moment it will ever exist — our server
 * cannot rebuild it, so a sender who closes this without copying has made
 * something nobody can open.
 */
export function SendSecretDialog({
    workspaceSlug,
    channelId = null,
    people,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    /**
     * The channel to announce it in, or null to announce it nowhere.
     *
     * Null is what the secrets page passes: a link made there goes into a mail
     * or over the phone, and a card in a room nobody asked about would be the
     * one thing that gives away that a credential changed hands.
     */
    channelId?: number | null;
    /**
     * Who it can be addressed to. The channel's members when there is one, the
     * whole workspace when there is not — and either way it stays optional,
     * because the link is the credential and a name is only a label.
     */
    people: { id: number; name: string }[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, tChoice } = useTranslate();
    const [recipientId, setRecipientId] = useState<string>('');
    const [label, setLabel] = useState('');
    const [secret, setSecret] = useState('');
    const [password, setPassword] = useState('');
    const [days, setDays] = useState(7);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /** The finished link, once there is one. Never leaves this component. */
    const [link, setLink] = useState<string | null>(null);
    const [copied, copy] = useClipboard();

    /**
     * The key belonging to the secret currently in flight.
     *
     * A ref rather than state: nothing renders from it, and the flash listener
     * below has to read the value that was current when the request went out —
     * not whatever a re-render happened to close over.
     */
    const pendingKey = useRef<string | null>(null);

    /**
     * The address comes back as an Inertia flash, which arrives as an event
     * rather than as a prop.
     *
     * Registered once for the dialog's lifetime instead of around each submit.
     * Tearing it down when the request finishes would be a bet on the flash
     * event firing before onFinish, and losing that bet means a sender who is
     * told nothing and has made a secret nobody can open.
     *
     * Deliberately not read out of page props either: a flash is gone by the
     * next page load, which is exactly the lifetime this belongs to. A prop
     * would linger in the history entry of a screen the sender has left.
     */
    useEffect(() => {
        return router.on('flash', (event) => {
            const sent = (event as CustomEvent).detail?.flash?.sentSecret as
                { url: string } | undefined;

            if (sent === undefined || pendingKey.current === null) {
                return;
            }

            /*
                The two halves put together for the first and only time: the
                address from the server, the key from this browser. Both are
                dropped afterwards — the secret has nothing left to do here.
            */
            setLink(linkWithKey(sent.url, pendingKey.current));
            pendingKey.current = null;
            setSecret('');
        });
    }, []);

    const reset = () => {
        setRecipientId('');
        setLabel('');
        setSecret('');
        setPassword('');
        setDays(7);
        setLink(null);
        setError(null);
    };

    const submit = async () => {
        // The recipient is deliberately not in this guard: naming somebody is
        // optional everywhere and required nowhere, so a link "voor niemand in
        // het bijzonder" has to get past here.
        if (saving || label.trim() === '' || secret === '') {
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const sealed = await sealSecret(secret);

            pendingKey.current = sealed.key;

            router.post(
                channelId === null
                    ? storeStandalone.url(workspaceSlug)
                    : store.url({
                          workspace: workspaceSlug,
                          channel: channelId,
                      }),
                {
                    recipient_id:
                        recipientId === '' ? null : Number(recipientId),
                    label,
                    // Two of the three. The key is deliberately absent, and this
                    // is the line that the whole design rests on.
                    ciphertext: sealed.ciphertext,
                    iv: sealed.iv,
                    password: password === '' ? null : password,
                    valid_for_days: days,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onError: () => {
                        pendingKey.current = null;
                        setError(t('dialogs.send_secret.error_form'));
                    },
                    onFinish: () => setSaving(false),
                },
            );
        } catch {
            setSaving(false);
            setError(t('dialogs.send_secret.error_crypto'));
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-lg">
                {link === null ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <KeyRound className="size-4" />
                                {t('dialogs.send_secret.title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('dialogs.send_secret.description')}
                                {channelId === null
                                    ? ` ${t('dialogs.send_secret.description_no_channel')}`
                                    : ''}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="secret-recipient">
                                    {t('dialogs.send_secret.recipient_label')}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        {t(
                                            'dialogs.send_secret.recipient_optional',
                                        )}
                                    </span>
                                </Label>
                                <select
                                    id="secret-recipient"
                                    value={recipientId}
                                    onChange={(event) =>
                                        setRecipientId(event.target.value)
                                    }
                                    className="w-full rounded-md border bg-background px-2 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <option value="">
                                        {t(
                                            'dialogs.send_secret.recipient_none',
                                        )}
                                    </option>
                                    {people.map((person) => (
                                        <option
                                            key={person.id}
                                            value={person.id}
                                        >
                                            {person.name}
                                        </option>
                                    ))}
                                </select>
                                {/*
                                    Said plainly, because the field looks like a
                                    permission and is not one. Whoever holds the
                                    link can open it, named or not.
                                */}
                                <p className="text-xs text-muted-foreground">
                                    {t('dialogs.send_secret.recipient_hint')}
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="secret-label">
                                    {t('dialogs.send_secret.label_label')}
                                </Label>
                                <Input
                                    id="secret-label"
                                    value={label}
                                    maxLength={120}
                                    placeholder={t(
                                        'dialogs.send_secret.label_placeholder',
                                    )}
                                    onChange={(event) =>
                                        setLabel(event.target.value)
                                    }
                                />
                                {/*
                                    Said out loud, because the label is the one
                                    field here that ends up in the channel where
                                    everybody can read it.
                                */}
                                <p className="text-xs text-muted-foreground">
                                    {channelId === null
                                        ? t(
                                              'dialogs.send_secret.label_hint_own',
                                          )
                                        : t(
                                              'dialogs.send_secret.label_hint_channel',
                                          )}
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="secret-value">
                                    {t('dialogs.send_secret.secret_label')}
                                </Label>
                                <textarea
                                    id="secret-value"
                                    value={secret}
                                    rows={3}
                                    maxLength={5000}
                                    className="w-full resize-none rounded-md border bg-background p-2 font-mono text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    onChange={(event) =>
                                        setSecret(event.target.value)
                                    }
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-2">
                                    <Label htmlFor="secret-password">
                                        {t(
                                            'dialogs.send_secret.password_label',
                                        )}
                                    </Label>
                                    <Input
                                        id="secret-password"
                                        type="text"
                                        value={password}
                                        maxLength={200}
                                        placeholder={t(
                                            'dialogs.send_secret.password_placeholder',
                                        )}
                                        onChange={(event) =>
                                            setPassword(event.target.value)
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="secret-days">
                                        {t('dialogs.send_secret.expires_label')}
                                    </Label>
                                    <select
                                        id="secret-days"
                                        value={days}
                                        onChange={(event) =>
                                            setDays(Number(event.target.value))
                                        }
                                        className="w-full rounded-md border bg-background px-2 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        {DAYS.map((day) => (
                                            <option key={day} value={day}>
                                                {tChoice(
                                                    'dialogs.send_secret.expires_days',
                                                    day,
                                                )}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            {/*
                                Only worth saying once there is one: a password
                                that travels beside the link protects nothing,
                                and that is the only mistake worth warning about
                                here.
                            */}
                            {password !== '' && (
                                <p className="text-xs text-muted-foreground">
                                    {t('dialogs.send_secret.password_hint')}
                                </p>
                            )}

                            {error !== null && (
                                <p className="text-sm text-destructive">
                                    {error}
                                </p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                {t('dialogs.actions.cancel')}
                            </Button>
                            <Button
                                disabled={
                                    saving ||
                                    label.trim() === '' ||
                                    secret === ''
                                }
                                onClick={() => void submit()}
                            >
                                {saving && <Spinner className="size-4" />}
                                {t('dialogs.send_secret.submit')}
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {t('dialogs.send_secret.link_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('dialogs.send_secret.link_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <span>
                                    {t('dialogs.send_secret.link_warning')}
                                </span>
                            </div>

                            <div className="rounded-md border bg-muted/40 p-2">
                                <code className="block font-mono text-xs break-all">
                                    {link}
                                </code>
                            </div>

                            <Button
                                className="w-full"
                                onClick={() => void copy(link)}
                            >
                                {copied === link ? (
                                    <>
                                        <Check className="size-4" />
                                        {t('dialogs.send_secret.link_copied')}
                                    </>
                                ) : (
                                    <>
                                        <Copy className="size-4" />
                                        {t('dialogs.send_secret.link_copy')}
                                    </>
                                )}
                            </Button>
                        </div>

                        <DialogFooter>
                            <Button onClick={() => onOpenChange(false)}>
                                {t('dialogs.send_secret.link_done')}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
