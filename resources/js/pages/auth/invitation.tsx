import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Hash, Lock } from 'lucide-react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { login, logout } from '@/routes';
import { accept } from '@/routes/invitations';
import type { TranslationKey } from '@/types/translations';

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

/**
 * Which words each dead end gets, named rather than spelled out: the lines
 * themselves live in lang/nl and lang/en, because whoever opens a stale
 * invitation may have no account here and therefore no language preference.
 */
const DEAD_END: Record<
    string,
    { title: TranslationKey; body: TranslationKey }
> = {
    expired: {
        title: 'auth_screens.invitation.expired_title',
        body: 'auth_screens.invitation.expired_body',
    },
    accepted: {
        title: 'auth_screens.invitation.accepted_title',
        body: 'auth_screens.invitation.accepted_body',
    },
    unknown: {
        title: 'auth_screens.invitation.unknown_title',
        body: 'auth_screens.invitation.unknown_body',
    },
};

function ChannelList({ channels }: { channels: string[] }) {
    const { t } = useTranslate();

    if (channels.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1.5 rounded-lg border p-3">
            <p className="text-xs font-medium text-muted-foreground">
                {t('auth_screens.invite.channels_intro')}
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
    const { t } = useTranslate();

    setLayoutProps({
        title: t('auth_screens.invite.title'),
        description: t('auth_screens.invite.description'),
    });

    if (state !== 'pending' || invitation === null || token === undefined) {
        const message = DEAD_END[state] ?? DEAD_END.unknown;

        return (
            <>
                <Head title={t('auth_screens.invitation.head')} />
                <div className="space-y-4 text-center">
                    <h2 className="text-lg font-medium">{t(message.title)}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(message.body)}
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <Link href={login()}>
                            {t('auth_screens.invite.to_login')}
                        </Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('auth_screens.invitation.head')} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {t('auth_screens.invite.invited_by', {
                            name: invitation.invitedBy,
                        })}
                    </p>
                    <p className="text-lg font-medium">
                        {invitation.workspaceName}
                    </p>
                    {invitation.isGuest && (
                        <p className="inline-flex items-center gap-1 rounded border border-amber-500/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <Lock className="size-3" />
                            {t('auth_screens.invite.as_guest')}
                        </p>
                    )}
                </div>

                {invitation.isGuest && (
                    <p className="text-center text-sm text-muted-foreground">
                        {t('auth_screens.invite.guest_note', {
                            workspace: invitation.workspaceName,
                        })}
                    </p>
                )}

                <ChannelList channels={invitation.channels} />

                {mode === 'mismatch' ? (
                    <div className="space-y-3 text-center">
                        <p className="text-sm text-muted-foreground">
                            {t('auth_screens.invitation.mismatch_intro')}{' '}
                            <span className="font-medium text-foreground">
                                {invitation.email}
                            </span>
                            {t('auth_screens.invitation.mismatch_rest', {
                                email: currentEmail ?? '',
                            })}
                        </p>
                        <Button asChild variant="outline" className="w-full">
                            <Link href={logout()} as="button">
                                {t('auth_screens.invitation.log_out')}
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
                                                {t('auth_screens.fields.email')}
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
                                            <Label htmlFor="name">
                                                {t('auth_screens.fields.name')}
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                type="text"
                                                required
                                                autoFocus
                                                autoComplete="name"
                                                placeholder={t(
                                                    'auth_screens.fields.name_placeholder',
                                                )}
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                {t(
                                                    'auth_screens.fields.password',
                                                )}
                                            </Label>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                required
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'auth_screens.fields.password',
                                                )}
                                                passwordrules={passwordRules}
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password_confirmation">
                                                {t(
                                                    'auth_screens.fields.password_confirm',
                                                )}
                                            </Label>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                required
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    'auth_screens.fields.password_confirm',
                                                )}
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
                                        {t(
                                            'auth_screens.invitation.account_exists_intro',
                                        )}{' '}
                                        <span className="font-medium text-foreground">
                                            {invitation.email}
                                        </span>
                                        {t(
                                            'auth_screens.invitation.account_exists_rest',
                                        )}
                                    </p>
                                )}

                                <Button type="submit" className="w-full">
                                    {processing && <Spinner />}
                                    {mode === 'login'
                                        ? t(
                                              'auth_screens.invitation.submit_login',
                                          )
                                        : t(
                                              'auth_screens.invitation.submit_accept',
                                          )}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
