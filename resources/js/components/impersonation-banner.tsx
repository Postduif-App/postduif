import { router, usePage } from '@inertiajs/react';
import { VenetianMask } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useTranslate } from '@/hooks/use-translate';
import { destroy as stopImpersonating } from '@/routes/impersonation';
import type { Auth } from '@/types/auth';

/**
 * The one thing on screen that says you are not yourself.
 *
 * Worth the space for the same reason the connection banner is: without it the
 * failure is silent. An impersonated session is not a mode with its own chrome
 * — it is the application, exactly as that person has it, which is the whole
 * point and also the hazard. Nothing else on any screen distinguishes reading
 * somebody's DMs from reading your own, and a message sent by accident arrives
 * under their name with nothing to mark it.
 *
 * Rendered around the whole application rather than per layout, because there
 * is no screen this may be missing from. It sits in `withApp` beside the
 * tooltips and the toasts — see app.tsx.
 *
 * Fixed at the bottom rather than the top, unlike the connection banner. The
 * top is where the chat puts a channel's name and its actions, and a bar that
 * covers those every day is a bar people learn to work around. The bottom-left
 * corner is empty on every screen in this application, and a session that ends
 * when you say so does not need to shout.
 */
export function ImpersonationBanner() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslate();

    if (!auth?.impersonator) {
        return null;
    }

    return (
        <div
            /*
             * Polite rather than assertive: it will still be there when the
             * sentence a screen reader is in the middle of has finished, and
             * cutting into that on every page change would be its own problem.
             */
            role="status"
            aria-live="polite"
            className="fixed bottom-3 left-3 z-50 flex max-w-[calc(100vw-1.5rem)] items-center gap-3 rounded-full border border-amber-500/40 bg-amber-500 py-1.5 pr-1.5 pl-3 text-sm text-amber-950 shadow-lg dark:bg-amber-400"
        >
            <VenetianMask className="size-4 shrink-0" aria-hidden="true" />
            <span className="min-w-0 truncate">
                {t('account.impersonation.banner', {
                    name: auth.user.name,
                })}
                <span className="hidden opacity-70 sm:inline">
                    {' · '}
                    {t('account.impersonation.aside', {
                        name: auth.impersonator.name,
                    })}
                </span>
            </span>
            <Button
                size="sm"
                variant="outline"
                className="h-7 shrink-0 rounded-full border-amber-950/30 bg-transparent text-amber-950 hover:bg-amber-950/10 hover:text-amber-950"
                /*
                 * A full page load rather than an Inertia visit. The response
                 * signs a different person in, and every prop this browser is
                 * holding — the sidebar, the open channel, the socket
                 * subscriptions — belongs to the member who is about to stop
                 * existing here. A visit would swap the page and keep all of
                 * that; a reload is what actually starts over.
                 */
                onClick={() =>
                    router.delete(stopImpersonating.url(), {
                        onSuccess: () => window.location.reload(),
                    })
                }
            >
                {t('account.impersonation.stop')}
            </Button>
        </div>
    );
}
