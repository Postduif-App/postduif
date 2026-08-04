import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import type { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import MarketingLayout from '@/layouts/marketing/layout';
import SettingsLayout from '@/layouts/settings/layout';
import { initializeWorkspaceTheme } from '@/lib/workspace-theme';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * The settings shell, widened for a page that manages a table.
 *
 * A named component at module scope rather than an arrow inside the resolver
 * below. Inertia treats what the resolver returns as a layout *component* and
 * calls it with the page's props — so an arrow taking the page element renders
 * that props object as a child, which is React error #31. Defining it here also
 * keeps its identity stable, so navigating between settings pages does not
 * remount the whole shell.
 */
function WideSettingsLayout({ children }: PropsWithChildren) {
    return <SettingsLayout wide>{children}</SettingsLayout>;
}

/** The auth shell with room for a list. Named at module scope for the same reason. */
function WideAuthLayout({ children }: PropsWithChildren) {
    return <AuthLayout wide>{children}</AuthLayout>;
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            // The public site brings its own shell. Its own rather than a
            // variation on the app's: the two have different jobs, and sharing
            // one would make every change to the app's chrome a change to the
            // marketing site.
            case name.startsWith('marketing/'):
                return MarketingLayout;
            // The chat page owns the full viewport and brings its own chrome.
            case name.startsWith('chat/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // A download link is followed by somebody who may have no account
            // here at all, which is exactly who the auth shell is built for:
            // one card, no navigation, nothing to sign in to.
            case name.startsWith('transfers/'):
                return AuthLayout;
            /*
             * The requester reading what came in. Wider than the rest of this
             * shell, because a decrypted key wrapped over four lines in a
             * 384px card is unusable — the same exception the member list gets
             * in settings.
             */
            case name === 'secrets/answers':
                return WideAuthLayout;
            // Answering a request for secrets is a single form for one person,
            // often a guest who never opens the chat. The same one-card shell
            // the download page uses, for the same reason.
            case name.startsWith('secrets/'):
                return AuthLayout;
            // The member list manages a table rather than a form, so it gets
            // the room a table needs. Every other settings page stays at
            // reading width.
            case name === 'settings/members':
                return WideSettingsLayout;
            /*
             * The workflow builder and its run history, for the same reason as
             * the member list: a row of steps with a form open on each is not a
             * thing that fits a reading column, and a run's context is JSON that
             * has to be readable rather than pretty.
             */
            case name === 'settings/workflows':
            case name === 'settings/workflow-runs':
                return WideSettingsLayout;
            // Settings bring their own full-height shell, in the same idiom as
            // the chat: no second application frame around it.
            case name.startsWith('settings/'):
                return SettingsLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

/**
 * A change to this file means a full page load, not a hot swap.
 *
 * createInertiaApp() mounts a React root on #app at module scope, and the rest
 * of what runs here — configureEcho, the theme initialisers — is module-scoped
 * too. A hot swap re-runs all of it against a page that already has it, and the
 * second createRoot on the same container is what surfaces later, and nowhere
 * near the cause, as "Node.removeChild: The node to be removed is not a child
 * of this node".
 *
 * Note what this deliberately does not do: mount the root by hand to make the
 * second run harmless. That needs react-dom/client imported here, which has
 * Vite pre-bundle a second copy of React DOM beside the one inside Inertia's
 * chunk — two React DOMs on one container, which is a worse version of the
 * problem it was meant to solve.
 */
if (import.meta.hot) {
    import.meta.hot.accept(() => window.location.reload());
}

// This will set light / dark mode on load...
initializeTheme();

// ...and this keeps the workspace's own accent and letter applied as you move
// between pages. The first paint is already themed from the server.
initializeWorkspaceTheme();
