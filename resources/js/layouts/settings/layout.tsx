import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Menu } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslate } from '@/hooks/use-translate';
import { cn, toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { index as openWorkspace } from '@/routes/chat';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as statusRules } from '@/routes/status-rules';
import { index as workflowsIndex } from '@/routes/workflows';
import { edit as editWorkspace } from '@/routes/workspace';
import { index as workspaceChannels } from '@/routes/workspace/channels';
import { index as contractWebhooks } from '@/routes/workspace/contract-webhooks';
import { index as workspaceEmoji } from '@/routes/workspace/emoji';
import { edit as editWorkspaceFeatures } from '@/routes/workspace/features';
import { index as workspaceInvitations } from '@/routes/workspace/invitations';
import { edit as editWorkspaceMail } from '@/routes/workspace/mail';
import { edit as editWorkspaceMailTexts } from '@/routes/workspace/mail-texts';
import { index as workspaceMembers } from '@/routes/workspace/members';
import { edit as editWorkspacePermissions } from '@/routes/workspace/permissions';
import { index as workspaceRoles } from '@/routes/workspace/roles';
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
    const { t } = useTranslate();

    const groups: NavGroup[] = [
        {
            title: t('settings.nav.account'),
            items: [
                { title: t('settings.nav.profile'), href: editProfile() },
                { title: t('settings.nav.security'), href: editSecurity() },
                {
                    title: t('settings.nav.notifications'),
                    href: editNotifications(),
                },
                { title: t('settings.nav.appearance'), href: editAppearance() },
                { title: t('settings.nav.status_rules'), href: statusRules() },
                { title: t('settings.nav.api_tokens'), href: apiTokens() },
            ],
        },
    ];

    // The workspace group is listed by ability rather than as a whole: an admin
    // sees all of it, and anybody who may only invite sees just that screen
    // instead of two links that would refuse to open.
    const workspaceItems: NavItem[] = [
        ...(auth.canManageWorkspace
            ? [
                  { title: t('settings.nav.general'), href: editWorkspace() },
                  {
                      title: t('settings.nav.permissions'),
                      href: editWorkspacePermissions(),
                  },
                  {
                      title: t('settings.nav.roles'),
                      href: workspaceRoles(),
                  },
                  {
                      title: t('settings.nav.emoji'),
                      href: workspaceEmoji(),
                  },
                  {
                      title: t('settings.nav.theme'),
                      href: editWorkspaceTheme(),
                  },
                  {
                      title: t('settings.nav.mail'),
                      href: editWorkspaceMail(),
                  },
                  {
                      title: t('settings.nav.mail_texts'),
                      href: editWorkspaceMailTexts(),
                  },
                  {
                      title: t('settings.nav.members'),
                      href: workspaceMembers(),
                  },
                  {
                      title: t('settings.nav.channels'),
                      href: workspaceChannels(),
                  },
                  /*
                   * Only where the workspace has workflows switched on. Listed
                   * by that rather than by role alone, unlike its neighbours:
                   * the screen answers 404 for a workspace that has them off,
                   * and a link that refuses to open is worse than no link.
                   */
                  ...(auth.canManageWorkflows
                      ? [
                            {
                                title: t('settings.nav.workflows'),
                                href: workflowsIndex(),
                            },
                        ]
                      : []),
                  /*
                   * And the same for the contract webhooks: only where the
                   * workspace has contracts at all, because the screen answers
                   * 404 without them.
                   */
                  ...(auth.canManageContractWebhooks
                      ? [
                            {
                                title: t('settings.nav.contract_webhooks'),
                                href: contractWebhooks(),
                            },
                        ]
                      : []),
              ]
            : []),
        /*
         * Outside the canManageWorkspace block, unlike everything above it: an
         * owner holds this right and an administrator does not, unless the
         * owner handed it over. Beside the invitations link for the same reason
         * — a screen somebody may open without running the whole workspace.
         */
        ...(auth.canManageFeatures
            ? [
                  {
                      title: t('settings.nav.features'),
                      href: editWorkspaceFeatures(),
                  },
              ]
            : []),
        ...(auth.canInviteToWorkspace
            ? [
                  {
                      title: t('settings.nav.invitations'),
                      href: workspaceInvitations(),
                  },
              ]
            : []),
    ];

    if (workspaceItems.length > 0) {
        groups.push({
            title: auth.workspace?.name ?? t('settings.nav.workspace'),
            items: workspaceItems,
        });
    }

    /*
     * The navigation, drawn once and placed twice: as the column it is on a
     * wide screen, and inside a sheet on one with no room for a standing 15rem
     * of it. The same reasoning — and the same shape — as the chat's channel
     * list.
     */
    const [navOpen, setNavOpen] = useState(false);

    // Choosing a page closes the sheet: Inertia keeps this layout mounted
    // across a visit, so nothing else would.
    useEffect(() => router.on('navigate', () => setNavOpen(false)), []);

    const nav = (
        <>
            <div className="flex h-14 items-center border-b border-sidebar-border px-2">
                {auth.workspace && (
                    <Link
                        href={openWorkspace(auth.workspace.slug)}
                        className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium hover:bg-sidebar-accent/50"
                    >
                        <ArrowLeft className="size-4 shrink-0 opacity-70" />
                        <span className="truncate">{auth.workspace.name}</span>
                    </Link>
                )}
            </div>

            <ScrollArea className="flex-1 px-2 py-4">
                <nav aria-label={t('settings.nav.label')} className="space-y-6">
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
        </>
    );

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <aside className="hidden h-full w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar lg:flex">
                {nav}
            </aside>

            <ScrollArea className="flex-1">
                {/*
                    The way into that navigation on a narrow screen. In the flow
                    at the top rather than floating over the page: settings are
                    read top to bottom and there is nothing here to keep in view
                    while scrolling.
                */}
                <div className="flex items-center gap-2 border-b px-4 py-2 lg:hidden">
                    <Sheet open={navOpen} onOpenChange={setNavOpen}>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="gap-2 text-muted-foreground"
                            >
                                <Menu className="size-4" />
                                {t('settings.nav.label')}
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            className="flex w-72 flex-col gap-0 border-sidebar-border bg-sidebar p-0"
                        >
                            <SheetTitle className="sr-only">
                                {t('settings.nav.label')}
                            </SheetTitle>
                            {nav}
                        </SheetContent>
                    </Sheet>
                </div>

                {/*
                    The rhythm between whatever a page puts on screen lives
                    here, not on the page. A page that renders three blocks —
                    a form, then the passkey list, then the danger zone — used
                    to space the first two itself and leave the third touching
                    its neighbour, because the wrapper it spaced them with did
                    not contain it. One column, one gap, no page can get it
                    wrong.
                */}
                <div
                    className={cn(
                        // A flex gap rather than `space-y`: most of these pages
                        // open with a screen-reader-only <h1>, which is
                        // positioned out of flow. `space-y` would still hang
                        // its margin on the block after it and push the whole
                        // page down; a flex gap skips it, as it skips anything
                        // absolutely positioned.
                        'mx-auto flex flex-col gap-10 px-4 py-10 pb-20 sm:px-8',
                        wide ? 'max-w-6xl' : 'max-w-2xl',
                    )}
                >
                    {children}
                </div>
            </ScrollArea>
        </div>
    );
}
