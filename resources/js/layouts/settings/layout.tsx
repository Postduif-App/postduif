import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { PropsWithChildren } from 'react';

import { ScrollArea } from '@/components/ui/scroll-area';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as openWorkspace } from '@/routes/chat';
import { index as mcpTokens } from '@/routes/mcp-tokens';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as statusRules } from '@/routes/status-rules';
import { edit as editWorkspace } from '@/routes/workspace';
import { index as workspaceInvitations } from '@/routes/workspace/invitations';
import { index as workspaceMembers } from '@/routes/workspace/members';
import { edit as editWorkspacePermissions } from '@/routes/workspace/permissions';
import { edit as editWorkspaceTheme } from '@/routes/workspace/theme';
import type { Auth, NavItem } from '@/types';

interface NavGroup {
    title: string;
    items: NavItem[];
}

/**
 * Settings, in the same shell as the rest of the application.
 *
 * These screens used to run on a layout of their own — a second sidebar, its
 * own breadcrumbs, its own idea of how wide a page is — which made "my account"
 * and "the workspace" look like one and the same thing while looking like
 * nothing else in the product. Here they are two labelled groups in one list,
 * and the way back to the conversation is the first thing on screen.
 */
/**
 * @param wide Widen the column for a page that manages a list rather than a
 *   form. A settings form reads better narrow — a line of text nobody has to
 *   sweep their eyes across — but a table of members needs every column it has,
 *   and squeezing one into the reading width is what made the member list hard
 *   to work with in the first place.
 */
export default function SettingsLayout({
    children,
    wide = false,
}: PropsWithChildren<{ wide?: boolean }>) {
    // Exact rather than prefix matching: /app/settings/workspace is the parent
    // path of /app/settings/workspace/members, so a prefix match would light up
    // "Algemeen" and "Leden" at the same time. Every screen here is a leaf.
    const { isCurrentUrl } = useCurrentUrl();
    const { auth } = usePage<{ auth: Auth }>().props;

    const groups: NavGroup[] = [
        {
            title: 'Jouw account',
            items: [
                { title: 'Profiel', href: editProfile() },
                { title: 'Beveiliging', href: editSecurity() },
                { title: 'Notificaties', href: editNotifications() },
                { title: 'Weergave', href: editAppearance() },
                { title: 'Statusregels', href: statusRules() },
                { title: 'AI-toegang', href: mcpTokens() },
            ],
        },
    ];

    // The workspace group is listed by ability rather than as a whole: an admin
    // sees all of it, and anybody who may only invite sees just that screen
    // instead of two links that would refuse to open.
    const workspaceItems: NavItem[] = [
        ...(auth.canManageWorkspace
            ? [
                  { title: 'Algemeen', href: editWorkspace() },
                  { title: 'Rechten', href: editWorkspacePermissions() },
                  { title: 'Thema', href: editWorkspaceTheme() },
                  { title: 'Leden', href: workspaceMembers() },
              ]
            : []),
        ...(auth.canInviteToWorkspace
            ? [{ title: 'Uitnodigingen', href: workspaceInvitations() }]
            : []),
    ];

    if (workspaceItems.length > 0) {
        groups.push({
            title: auth.workspace?.name ?? 'Workspace',
            items: workspaceItems,
        });
    }

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <aside className="flex h-full w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
                <div className="flex h-14 items-center border-b border-sidebar-border px-2">
                    {auth.workspace && (
                        <Link
                            href={openWorkspace(auth.workspace.slug)}
                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium hover:bg-sidebar-accent/50"
                        >
                            <ArrowLeft className="size-4 shrink-0 opacity-70" />
                            <span className="truncate">
                                {auth.workspace.name}
                            </span>
                        </Link>
                    )}
                </div>

                <ScrollArea className="flex-1 px-2 py-4">
                    <nav aria-label="Instellingen" className="space-y-6">
                        {groups.map((group) => (
                            <div key={group.title}>
                                <h2 className="truncate px-2 pb-1 text-xs font-semibold tracking-wide text-sidebar-foreground/50 uppercase">
                                    {group.title}
                                </h2>
                                <div className="space-y-0.5">
                                    {group.items.map((item) => (
                                        <Link
                                            key={toUrl(item.href)}
                                            href={item.href}
                                            className={cn(
                                                'block rounded-md px-2 py-1.5 text-sm transition-colors',
                                                isCurrentUrl(item.href)
                                                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                                    : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                            )}
                                        >
                                            {item.title}
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                </ScrollArea>
            </aside>

            <ScrollArea className="flex-1">
                <div
                    className={cn(
                        'mx-auto px-6 py-8',
                        wide ? 'max-w-6xl' : 'max-w-2xl',
                    )}
                >
                    {children}
                </div>
            </ScrollArea>
        </div>
    );
}
