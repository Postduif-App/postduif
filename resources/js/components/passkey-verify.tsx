import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

/**
 * The passkey button, and the "or" line under it.
 *
 * The words are optional and default to the login screen's, because that is
 * what this does when no routes are handed in: it signs somebody in. A screen
 * that verifies for another reason says so itself — see confirm-password, which
 * passes its own three lines.
 *
 * The defaults come from the translations rather than from English string
 * literals. They were literals, which left the login screen with one English
 * sentence in the middle of a translated page — and that page is one of the few
 * whose reader never set a language, so it was the wrong place of all to leave
 * it.
 */
export default function PasskeyVerify({
    routes,
    label,
    loadingLabel,
    separator,
}: Props = {}) {
    const { t } = useTranslate();
    const { verify, isLoading, error, isSupported } = usePasskeyVerify({
        ...(routes && {
            routes: {
                options: routes.options.url,
                submit: routes.submit.url,
            },
        }),
        onSuccess: (response) => {
            router.visit(response.redirect ?? '/app');
        },
    });

    if (!isSupported) {
        return null;
    }

    return (
        <>
            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={verify}
                    disabled={isLoading}
                >
                    {isLoading ? <Spinner /> : <KeyRound className="h-4 w-4" />}
                    {isLoading
                        ? (loadingLabel ??
                          t('auth_screens.login.passkey_loading'))
                        : (label ?? t('auth_screens.login.passkey'))}
                </Button>
                {error && (
                    <InputError message={error} className="text-center" />
                )}
            </div>

            <div className="relative my-6">
                <div className="absolute inset-0 flex items-center">
                    <Separator className="w-full" />
                </div>
                <div className="relative flex justify-center text-xs uppercase">
                    <span className="bg-background px-2 text-muted-foreground">
                        {separator ?? t('auth_screens.login.passkey_separator')}
                    </span>
                </div>
            </div>
        </>
    );
}
