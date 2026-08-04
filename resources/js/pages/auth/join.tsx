import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Hash, Lock } from 'lucide-react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { login } from '@/routes';
import { join } from '@/routes/invite-links';
import type { TranslationKey } from '@/types/translations';

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
 *
 * Named rather than spelled out here: the words themselves live in lang/nl and
 * lang/en, because whoever follows a dead link may have no account and so no
 * language of their own on file.
 */
const DEAD_END: Record<
    string,
    { title: TranslationKey; body: TranslationKey }
> = {
    expired: {
        title: 'auth_screens.join.expired_title',
        body: 'auth_screens.join.expired_body',
    },
    revoked: {
        title: 'auth_screens.join.revoked_title',
        body: 'auth_screens.join.revoked_body',
    },
    exhausted: {
        title: 'auth_screens.join.exhausted_title',
        body: 'auth_screens.join.exhausted_body',
    },
    unknown: {
        title: 'auth_screens.join.unknown_title',
        body: 'auth_screens.join.unknown_body',
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

export default function JoinPage({
    state,
    mode,
    token,
    currentEmail,
    passwordRules,
    link,
}: JoinProps) {
    const { t } = useTranslate();

    setLayoutProps({
        title: t('auth_screens.invite.title'),
        description: t('auth_screens.invite.description'),
    });

    if (state !== 'usable' || link === null || token === undefined) {
        const message = DEAD_END[state] ?? DEAD_END.unknown;

        return (
            <>
                <Head title={t('auth_screens.join.head')} />
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
            <Head title={t('auth_screens.join.head')} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {link.invitedBy
                            ? t('auth_screens.invite.invited_by', {
                                  name: link.invitedBy,
                              })
                            : t('auth_screens.join.invited_generic')}
                    </p>
                    <p className="text-lg font-medium">{link.workspaceName}</p>
                    {link.isGuest && (
                        <p className="inline-flex items-center gap-1 rounded border border-amber-500/40 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <Lock className="size-3" />
                            {t('auth_screens.invite.as_guest')}
                        </p>
                    )}
                </div>

                {link.isGuest && (
                    <p className="text-center text-sm text-muted-foreground">
                        {t('auth_screens.invite.guest_note', {
                            workspace: link.workspaceName,
                        })}
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
                                            {t('auth_screens.fields.email')}
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
                                            placeholder={t(
                                                'auth_screens.join.email_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.email} />
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
                                            autoComplete="name"
                                            placeholder={t(
                                                'auth_screens.fields.name_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            {t('auth_screens.fields.password')}
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
                                        <InputError message={errors.password} />
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

                            {mode === 'accept' && currentEmail && (
                                <p className="text-center text-sm text-muted-foreground">
                                    {t('auth_screens.join.signed_in_as')}{' '}
                                    <span className="font-medium text-foreground">
                                        {currentEmail}
                                    </span>
                                    .
                                </p>
                            )}

                            <Button type="submit" className="w-full">
                                {processing && <Spinner />}
                                {t('auth_screens.join.submit')}
                            </Button>

                            {mode === 'register' && (
                                <p className="text-center text-sm text-muted-foreground">
                                    {t('auth_screens.join.have_account')}{' '}
                                    {/*
                                        The join page put itself down as the
                                        intended URL, so logging in lands back
                                        here — signed in, one button away.
                                    */}
                                    <Link
                                        href={login()}
                                        className="font-medium text-foreground underline underline-offset-4"
                                    >
                                        {t('auth_screens.join.log_in_first')}
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
