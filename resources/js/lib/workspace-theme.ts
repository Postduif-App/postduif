import { router } from '@inertiajs/react';

const STYLE_ID = 'workspace-theme';

interface WorkspaceThemeProps {
    theme?: { css?: string };
}

/**
 * Applies the workspace's accent and letter, and keeps applying it.
 *
 * The stylesheet is rendered server-side into app.blade.php, so the first paint
 * is already themed. This takes over from there: saving new settings, or
 * landing on a page belonging to a differently themed workspace, arrives as a
 * prop and has to reach that same <style> element rather than a second one
 * stacked on top of it.
 *
 * A router subscription rather than a component with usePage(): the theme is
 * true of the document, not of any page, and there is no one place in the tree
 * that every screen passes through — the chat and settings screens both bring
 * their own shell, and app.tsx's withApp() sits outside the Inertia context
 * where usePage() has nothing to read.
 */
export function initializeWorkspaceTheme(): void {
    if (typeof document === 'undefined') {
        return;
    }

    router.on('navigate', (event) => {
        const { theme } = event.detail.page.props as WorkspaceThemeProps;

        applyWorkspaceTheme(theme?.css ?? '');
    });
}

function applyWorkspaceTheme(css: string): void {
    let style = document.getElementById(STYLE_ID);

    if (!style) {
        style = document.createElement('style');
        style.id = STYLE_ID;
        document.head.append(style);
    }

    style.textContent = css;
}
