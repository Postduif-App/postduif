// Components
import { Form, Head, setLayoutProps } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useTranslate();

    setLayoutProps({
        title: t('auth_screens.verify_email.title'),
        description: t('auth_screens.verify_email.description'),
    });

    return (
        <>
            <Head title={t('auth_screens.verify_email.head')} />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {t('auth_screens.verify_email.sent')}
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t('auth_screens.verify_email.resend')}
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            {t('auth_screens.verify_email.log_out')}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}
