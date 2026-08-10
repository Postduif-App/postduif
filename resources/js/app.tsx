import { createInertiaApp, router } from '@inertiajs/react';
import { configureEcho, echo, echoIsConfigured } from '@laravel/echo-react';
import type { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import ChatLayout from '@/layouts/chat-layout';
import MarketingLayout from '@/layouts/marketing/layout';
import SettingsLayout from '@/layouts/settings/layout';
import { initializeWorkspaceTheme } from '@/lib/workspace-theme';

/**
 * `wsPath` because Reverb serves the socket on `/app/{key}`, and this
 * application already owns `/app/*`. On a deployment where the proxy puts both
 * on one domain, Reverb runs under a prefix instead — and this is compiled in,
 * so changing it means a rebuild rather than a restart. Empty in development,
 * where Reverb has a port to itself and needs no prefix at all.
 */
const reverbPath = (import.meta.env.VITE_REVERB_PATH ?? '').replace(
    /^\/+|\/+$/g,
    '',
);

configureEcho({
    broadcaster: 'reverb',
    wsPath: reverbPath ? `/${reverbPath}` : '',
});

/**
 * Tell the server which socket a request came from, so a broadcast can leave
 * its own author out.
 *
 * This is the whole of what `broadcast(...)->toOthers()` needs. Laravel reads
 * it from the X-Socket-ID header and nothing else — no header, and toOthers()
 * quietly becomes a plain broadcast that also reaches whoever caused it.
 *
 * Quietly is the problem. The document editor autosaves every few seconds while
 * somebody types, and without this each of those saves came straight back to
 * the person typing as "somebody else has updated this document".
 *
 * Read per request rather than once here: the socket id does not exist until
 * the connection is up, and it changes on every reconnect.
 */
router.on('before', (event) => {
    const id = echoIsConfigured() ? echo().socketId() : null;

    if (id) {
        event.detail.visit.headers['X-Socket-ID'] = id;
    }
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

/*
 * Mounted once per document, even when this module runs twice.
 *
 * See the note at the foot of this file: a hot update to anything this module
 * imports directly re-executes the whole of it, and the createInertiaApp()
 * below would then mount a second React root on the same #app — which React
 * reports as "you are calling createRoot() on a container that has already been
 * passed to createRoot()", and which later surfaces as a removeChild failure
 * nowhere near its cause. The reload underneath cleans that up a moment later,
 * but a moment is long enough to break the page you are looking at.
 *
 * import.meta.hot.data is the bag Vite keeps across re-executions of one
 * module, which is exactly the lifetime this question has. It does not exist in
 * a built bundle, so there the condition is simply true and this reads as an
 * ordinary call.
 */
if (import.meta.hot?.data.mounted !== true) {
    if (import.meta.hot) {
        import.meta.hot.data.mounted = true;
    }

    createInertiaApp({
        title: (title) => (title ? `${title} - ${appName}` : appName),
        layout: (name) => {
            switch (true) {
                case name === 'welcome':
                    return null;
                /*
                 * Setting up a platform that has nothing in it yet. No shell at
                 * all: the auth card is built for somebody arriving at an
                 * application that exists, and this screen has to explain what is
                 * about to exist beside the form that makes it. It brings its own
                 * two halves — see pages/install/welcome.
                 */
                case name === 'install/welcome':
                    return null;
                // The public site brings its own shell. Its own rather than a
                // variation on the app's: the two have different jobs, and sharing
                // one would make every change to the app's chrome a change to the
                // marketing site.
                case name.startsWith('marketing/'):
                    return MarketingLayout;
                /*
                 * The chat page owns the full viewport and brings its own chrome,
                 * so its shell adds no frame at all — only the banner that warns
                 * every chat screen at once when the socket they all live on is
                 * gone.
                 */
                case name.startsWith('chat/'):
                    return ChatLayout;
                case name.startsWith('auth/'):
                    return AuthLayout;
                // Making your first workspace. The auth shell rather than the chat
                // one: there is no sidebar to draw, because there is nothing yet to
                // put in it.
                case name.startsWith('workspaces/'):
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
                /*
                 * Filling in a form, through either door. The public one is
                 * followed by somebody with no account at all — the same case the
                 * download page is built for — and the member's page is the same
                 * screen with a name attached, so it gets the same shell rather
                 * than a second one that would have to be kept in step.
                 */
                case name.startsWith('forms/'):
                    return AuthLayout;
                /*
                 * The two settings pages that manage a table rather than a form, so
                 * they get the room a table needs: the member list, and the channel
                 * table whose counts and typed-in topics do not fit a reading
                 * column either. Every other settings page stays at reading width.
                 */
                case name === 'settings/members':
                case name === 'settings/workspace-channels':
                    return WideSettingsLayout;
                /*
                 * The workflow builder and its run history, for the same reason as
                 * the member list: a canvas with a panel beside it is not a thing
                 * that fits a reading column, and a run's context is JSON that has
                 * to be readable rather than pretty.
                 *
                 * The builder is the one that needs it most — it draws lanes inside
                 * forks, and every level of nesting eats into the width the blocks
                 * have left.
                 */
                case name === 'settings/workflows':
                case name === 'settings/workflow-edit':
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
}

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
