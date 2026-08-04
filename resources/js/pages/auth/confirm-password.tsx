import { Form, Head, setLayoutProps } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const { t } = useTranslate();

    setLayoutProps({
        title: t('auth_screens.confirm_password.title'),
        description: t('auth_screens.confirm_password.description'),
    });

    return (
        <>
            <Head title={t('auth_screens.confirm_password.head')} />

            <PasskeyVerify
                routes={{
                    options: confirmOptions(),
                    submit: confirmStore(),
                }}
                label={t('auth_screens.confirm_password.passkey')}
                loadingLabel={t(
                    'auth_screens.confirm_password.passkey_loading',
                )}
                separator={t('auth_screens.confirm_password.passkey_separator')}
            />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('auth_screens.fields.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={t('auth_screens.fields.password')}
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {t('auth_screens.confirm_password.submit')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}
