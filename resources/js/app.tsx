import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import type { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
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

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            // The chat page owns the full viewport and brings its own chrome.
            case name.startsWith('chat/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // The member list manages a table rather than a form, so it gets
            // the room a table needs. Every other settings page stays at
            // reading width.
            case name === 'settings/members':
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

// This will set light / dark mode on load...
initializeTheme();

// ...and this keeps the workspace's own accent and letter applied as you move
// between pages. The first paint is already themed from the server.
initializeWorkspaceTheme();
