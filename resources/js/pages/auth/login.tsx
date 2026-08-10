import { Form, Head, setLayoutProps, usePage } from '@inertiajs/react';
import { DevQuickLogin } from '@/components/dev-quick-login';
import type { DevAccount } from '@/components/dev-quick-login';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    /** Empty outside local development, which is what hides the buttons. */
    devAccounts?: DevAccount[];
};

export default function Login({
    status,
    canResetPassword,
    devAccounts = [],
}: Props) {
    const { t } = useTranslate();

    // Shared rather than a page prop: every auth screen may need to know, and
    // the sign-up page is not the only place a closed door has to be honoured.
    const { registrationOpen } = usePage<{ registrationOpen: boolean }>().props;

    /*
     * Set from inside the component rather than on a static `Login.layout`,
     * which is where the heading and the line under it used to live: the words
     * come from the translation prop now, and reading that is a hook — which
     * only a component body may do.
     */
    setLayoutProps({
        title: t('auth_screens.login.title'),
        description: t('auth_screens.login.description'),
    });

    return (
        <>
            <Head title={t('auth_screens.login.head')} />

            {/*
                Above the form rather than under it, where it used to sit with a
                bottom margin that had nothing left to push away. This is what
                answers "did my herstellink go out?", so it belongs where the
                question was asked — and the wrapper stays mounted whether or
                not there is a status, because a live region has to be in the
                document before its contents change to be read aloud.
            */}
            <div aria-live="polite" className="empty:hidden">
                {status && (
                    <p className="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-400">
                        {status}
                    </p>
                )}
            </div>

            <PasskeyVerify />

            <DevQuickLogin accounts={devAccounts} />

            {/*
                No tabIndex anywhere in here, where the fields used to be
                numbered 1 through 5. Any number above zero moves an element
                into a tab order of its own that the browser walks *before*
                everything else on the page — so the numbered form came first
                and the passkey button above it came last, which is the reverse
                of what somebody looking at the screen sees. Document order was
                already right; the numbers were the only thing breaking it.
            */}
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth_screens.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    aria-invalid={Boolean(errors.email)}
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                />
                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {t('auth_screens.fields.password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                        >
                                            {t(
                                                'auth_screens.login.forgot_password',
                                            )}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder={t(
                                        'auth_screens.fields.password',
                                    )}
                                    aria-invalid={Boolean(errors.password)}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-error'
                                            : undefined
                                    }
                                />
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">
                                    {t('auth_screens.login.remember')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                aria-busy={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('auth_screens.login.submit')}
                            </Button>
                        </div>

                        {/*
                            Left out entirely rather than disabled when the
                            installation has closed registration: the page it
                            points at answers with a 404, and an offer that
                            leads nowhere is worse than no offer.
                        */}
                        {registrationOpen && (
                            <div className="text-center text-sm text-muted-foreground">
                                {t('auth_screens.login.no_account')}{' '}
                                <TextLink href={register()}>
                                    {t('auth_screens.login.sign_up')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}
