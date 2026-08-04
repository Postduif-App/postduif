import { Head } from '@inertiajs/react';
import { Check, Copy, Eye, KeyRound, Lock } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import AuthLayout from '@/layouts/auth-layout';
import { keyFromFragment, openSecret } from '@/lib/secret-crypto';
import { reveal } from '@/routes/sent-secrets';

interface RevealPageProps {
    secret: {
        id: string;
        label: string;
        senderName: string;
        recipientName: string;
        expiresAt: string;
        revealedAt: string | null;
        state: 'pending' | 'revealed' | 'expired';
        needsPassword: boolean;
    };
}

/**
 * Picking up a secret somebody sent you.
 *
 * Two things here are deliberate and easy to undo by accident.
 *
 * The first is that nothing happens on load. A secret that shows itself the
 * moment the page opens is a secret that burns because a link preview fetched
 * it, or because somebody clicked through to see what it was. It takes a press.
 *
 * The second is that the decryption happens here and the key comes out of the
 * fragment of this URL — which the browser never sent. If the fragment is
 * missing, this page is looking at bytes it cannot read, and no amount of
 * asking the server will change that.
 */
export default function RevealSecret({ secret }: RevealPageProps) {
    const [password, setPassword] = useState('');
    const [plaintext, setPlaintext] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, copy] = useClipboard();
    const { t } = useTranslate();
    const formats = useFormats();

    // Read once, on first render. It is in the address bar the whole time, but
    // reading it during render keeps this out of an effect that would run again
    // for reasons that have nothing to do with the URL.
    const [key] = useState(() =>
        typeof window === 'undefined'
            ? null
            : keyFromFragment(window.location.hash),
    );

    const submit = async () => {
        if (busy || key === null) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const response = await fetch(reveal.url(secret.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    ),
                },
                body: JSON.stringify({ password: password || null }),
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    reason?: string;
                };

                setError(
                    body.reason === 'password'
                        ? t('account.secret_reveal.error_password')
                        : body.reason === 'revealed'
                          ? t('account.secret_reveal.error_revealed')
                          : t('account.secret_reveal.error_gone'),
                );

                return;
            }

            const { ciphertext, iv } = (await response.json()) as {
                ciphertext: string;
                iv: string;
            };

            /*
                The one place the words exist again. Held in component state and
                nowhere else: not in the URL, not in a store, not in anything
                that survives this page.
            */
            setPlaintext(await openSecret(ciphertext, iv, key));
        } catch {
            /*
                Either the network gave out or the ciphertext would not open.
                Both are said the same way on purpose — if decryption failed the
                secret has already been spent server-side, and offering a retry
                would be sending somebody back for something that is gone.
            */
            setError(t('account.secret_reveal.error_open'));
        } finally {
            setBusy(false);
        }
    };

    const gone = secret.state !== 'pending';

    return (
        <AuthLayout
            title={
                plaintext === null
                    ? t('account.secret_reveal.title')
                    : secret.label
            }
            description={
                plaintext === null
                    ? t('account.secret_reveal.description', {
                          sender: secret.senderName,
                          recipient: secret.recipientName,
                      })
                    : t('account.secret_reveal.copy_now')
            }
        >
            <Head title={t('account.secret_reveal.head')} />

            <div className="space-y-4">
                {/* Said in the open by the sender, so safe to show before anything. */}
                <div className="flex items-start gap-3 rounded-md border p-3">
                    <KeyRound className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 text-sm">
                        <p className="font-medium">{secret.label}</p>
                        <p className="text-xs text-muted-foreground">
                            {t('account.secret_reveal.expires_at', {
                                moment: formats.longDateTime.format(
                                    new Date(secret.expiresAt),
                                ),
                            })}
                        </p>
                    </div>
                </div>

                {gone ? (
                    /*
                        A mededeling, not an error — the brief is explicit about
                        it. Somebody arriving here second has done nothing wrong,
                        and a red failure would send them looking for a fault
                        that is not there.
                    */
                    <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                        {secret.state === 'revealed'
                            ? t('account.secret_reveal.already_revealed')
                            : t('account.secret_reveal.expired')}
                    </p>
                ) : key === null ? (
                    /*
                        No fragment, so no key. Worth its own message: the link
                        was almost certainly truncated on its way here, and
                        "probeer opnieuw" would be advice that cannot work.
                    */
                    <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                        {t('account.secret_reveal.no_key')}
                    </p>
                ) : plaintext === null ? (
                    <>
                        {secret.needsPassword && (
                            <div className="space-y-2">
                                <Label htmlFor="secret-password">
                                    {t('account.secret_reveal.password')}
                                </Label>
                                <Input
                                    id="secret-password"
                                    type="password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            void submit();
                                        }
                                    }}
                                />
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Lock className="size-3" />
                                    {t('account.secret_reveal.password_note')}
                                </p>
                            </div>
                        )}

                        <Button
                            className="w-full"
                            disabled={busy}
                            onClick={() => void submit()}
                        >
                            {busy ? (
                                <Spinner className="size-4" />
                            ) : (
                                <Eye className="size-4" />
                            )}
                            {t('account.secret_reveal.submit')}
                        </Button>

                        <p className="text-center text-xs text-muted-foreground">
                            {t('account.secret_reveal.once')}
                        </p>
                    </>
                ) : (
                    <>
                        <div className="rounded-md border bg-muted/40 p-3">
                            <pre className="overflow-x-auto font-mono text-sm whitespace-pre-wrap">
                                {plaintext}
                            </pre>
                        </div>

                        <Button
                            className="w-full"
                            onClick={() => void copy(plaintext)}
                        >
                            {copied === plaintext ? (
                                <>
                                    <Check className="size-4" />
                                    {t('account.secret_reveal.copied')}
                                </>
                            ) : (
                                <>
                                    <Copy className="size-4" />
                                    {t('account.secret_reveal.copy')}
                                </>
                            )}
                        </Button>

                        {/*
                            Stated plainly rather than softened. There is no way
                            back and the page must not imply there is one — a
                            refresh from here shows "al opgehaald".
                        */}
                        <p className="text-center text-xs text-muted-foreground">
                            {t('account.secret_reveal.gone_note')}
                        </p>
                    </>
                )}

                {error !== null && (
                    <p className="text-sm text-destructive">{error}</p>
                )}
            </div>
        </AuthLayout>
    );
}
