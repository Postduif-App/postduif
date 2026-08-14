import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    AtSign,
    Hash,
    MoreHorizontal,
    Search,
    UserMinus,
    UserPlus,
    VenetianMask,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import type { InvitingWorkspace } from '@/components/chat/invite-people-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { GuestChannelsDialog } from '@/components/guest-channels-dialog';
import type { ChannelOption } from '@/components/guest-channels-dialog';
import { MemberHandleDialog } from '@/components/member-handle-dialog';
import { SettingsSection } from '@/components/settings-section';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormats } from '@/hooks/use-formats';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    destroy as removeMember,
    impersonate as impersonateMember,
    update as updateRole,
} from '@/routes/workspace/members';
import type { Availability } from '@/types/auth';

interface Option {
    /** The role's id. Sent as a number and rendered as a string: a Select
     *  compares its value by identity, and one side arriving as a number is
     *  how a dropdown ends up showing nothing selected. */
    value: number;
    label: string;
}

interface WorkspaceMember {
    id: number;
    name: string;
    username: string;
    /** The id of the role row, which is what the select posts back. */
    role: number;
    roleLabel: string;
    /** What the badge draws with: an id says nothing about what a role is. */
    roleManages: boolean;
    roleIsExternal: boolean;
    joinedAt: string | null;
    statusEmoji: string | null;
    statusText: string | null;
    availability: Availability;
    /** Guests only: the channels they were let into. Null for everyone else. */
    channelIds: number[] | null;
    canChangeRole: boolean;
    /** Renaming somebody reaches outside this workspace — see the dialog. */
    canChangeHandle: boolean;
    canRemove: boolean;
    canManageChannels: boolean;
    /** Its own right, and never true for yourself — see WorkspacePolicy. */
    canImpersonate: boolean;
}

interface MembersProps {
    workspaceName: string;
    /** The invite endpoint names its workspace in the URL. */
    workspaceSlug: string;
    /** False for a role that manages the workspace but may not bring people in. */
    canInvite: boolean;
    /** The roles this member may hand out, for the invite dialog. */
    invitableRoles: InvitingWorkspace['invitableRoles'];
    members: WorkspaceMember[];
    roleOptions: Option[];
    channelOptions: ChannelOption[];
}

/** Below this a filter box is clutter rather than help. */
const FILTER_FROM = 8;

type SortKey = 'name' | 'username' | 'role' | 'joinedAt';

interface Sort {
    key: SortKey;
    ascending: boolean;
}

/**
 * The server hands the list back in standing order — owners first, guests last,
 * alphabetical within each. That is the answer to "who runs this workspace",
 * which is what people open this page for, so it is what the table shows until
 * somebody asks for something else.
 */
const DEFAULT_SORT: Sort = { key: 'role', ascending: true };

/**
 * Sorting happens in the browser rather than through the server.
 *
 * The whole list is already on the page — it is one workspace's members, not a
 * feed — so a round trip per click would cost a page load to reorder rows that
 * are all sitting here already.
 *
 * The role column restores the order the server sent instead of ranking the
 * roles again: that ranking lives in SystemRole::rank(), and a second copy
 * here would be the one that goes stale when a role is added.
 */
function sortMembers(
    members: WorkspaceMember[],
    sort: Sort,
    serverOrder: Map<number, number>,
): WorkspaceMember[] {
    const compare = (a: WorkspaceMember, b: WorkspaceMember): number => {
        switch (sort.key) {
            case 'name':
                return a.name.localeCompare(b.name, 'nl');
            case 'username':
                return a.username.localeCompare(b.username, 'nl');
            case 'joinedAt':
                // Missing sorts last whichever way round: "we do not know" is
                // not the same as "the oldest possible date".
                if (!a.joinedAt || !b.joinedAt) {
                    return a.joinedAt ? -1 : b.joinedAt ? 1 : 0;
                }

                return a.joinedAt.localeCompare(b.joinedAt);
            case 'role':
                return (
                    (serverOrder.get(a.id) ?? 0) - (serverOrder.get(b.id) ?? 0)
                );
        }
    };

    return [...members].sort((a, b) =>
        sort.ascending ? compare(a, b) : compare(b, a),
    );
}

function SortableHeader({
    label,
    column,
    sort,
    onSort,
    className,
}: {
    label: string;
    column: SortKey;
    sort: Sort;
    onSort: (key: SortKey) => void;
    className?: string;
}) {
    const active = sort.key === column;

    return (
        <th
            scope="col"
            className={cn('px-3 py-2 text-left', className)}
            aria-sort={
                active ? (sort.ascending ? 'ascending' : 'descending') : 'none'
            }
        >
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'flex items-center gap-1 rounded text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                    active
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                {label}
                {active &&
                    (sort.ascending ? (
                        <ArrowUp className="size-3" />
                    ) : (
                        <ArrowDown className="size-3" />
                    ))}
            </button>
        </th>
    );
}

function MemberRow({
    member,
    roleOptions,
    onRemove,
    onEditChannels,
    onEditHandle,
    onImpersonate,
}: {
    member: WorkspaceMember;
    roleOptions: Option[];
    onRemove: (member: WorkspaceMember) => void;
    onEditChannels: (member: WorkspaceMember) => void;
    onEditHandle: (member: WorkspaceMember) => void;
    onImpersonate: (member: WorkspaceMember) => void;
}) {
    const getInitials = useInitials();
    const { t } = useTranslate();
    const formats = useFormats();

    const joined = member.joinedAt
        ? formats.mediumDate.format(new Date(member.joinedAt))
        : null;

    /*
     * Drawn once and placed twice: as its own column where there is room for
     * one, and under the name where there is not.
     */
    const channels = member.channelIds ? (
        <span className="inline-flex items-center gap-1">
            <Hash className="size-3" />
            {member.channelIds.length}
        </span>
    ) : (
        // For everyone else it is the whole workspace, and a number here would
        // suggest a boundary that is not there.
        <span className="opacity-60">{t('settings.members.all_channels')}</span>
    );

    return (
        <tr className="border-t">
            <td className="px-3 py-2">
                <div className="flex items-center gap-2.5">
                    <span className="relative flex size-8 shrink-0 items-center justify-center rounded bg-muted text-xs font-semibold">
                        {getInitials(member.name)}
                        {/*
                            Only when it says something. "Beschikbaar" is the
                            default state of everybody, and a dot on every row
                            is a dot nobody reads.
                        */}
                        {member.availability !== 'available' && (
                            <span className="absolute -right-0.5 -bottom-0.5 rounded-full bg-background p-px">
                                <AvailabilityDot
                                    availability={member.availability}
                                />
                            </span>
                        )}
                    </span>
                    <div className="min-w-0">
                        <span className="block truncate text-sm font-medium">
                            {member.name}
                        </span>

                        {/* What the three columns that stepped aside were
                            carrying. */}
                        <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground lg:hidden">
                            <span className="truncate">@{member.username}</span>
                            {joined && <span>· {joined}</span>}
                            <span aria-hidden="true">·</span>
                            {channels}
                        </span>
                    </div>
                </div>
            </td>

            <td className="hidden px-3 py-2 text-sm text-muted-foreground lg:table-cell">
                @{member.username}
            </td>

            <td className="px-3 py-2">
                {member.canChangeRole ? (
                    <Select
                        value={String(member.role)}
                        onValueChange={(role) =>
                            router.patch(
                                updateRole.url(member.id),
                                { role },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <SelectTrigger
                            className="w-36"
                            aria-label={t('settings.members.role_of', {
                                name: member.name,
                            })}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roleOptions.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={String(option.value)}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <span
                        className={cn(
                            'inline-block rounded px-2 py-0.5 text-xs font-medium',
                            // Only the roles that carry authority get the
                            // accent. Somebody from outside is set apart the
                            // other way: outlined, because it says "external"
                            // rather than "elevated".
                            !member.roleManages &&
                                !member.roleIsExternal &&
                                'bg-muted text-muted-foreground',
                            member.roleIsExternal &&
                                'border border-amber-500/40 text-amber-700 dark:text-amber-400',
                            member.roleManages && 'bg-primary/10 text-primary',
                        )}
                    >
                        {member.roleLabel}
                    </span>
                )}
            </td>

            <td className="max-w-48 px-3 py-2">
                {member.statusText ? (
                    <MemberStatus
                        emoji={member.statusEmoji}
                        text={member.statusText}
                    />
                ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                )}
            </td>

            <td className="hidden px-3 py-2 text-xs whitespace-nowrap text-muted-foreground lg:table-cell">
                {joined ?? '—'}
            </td>

            {/*
                The guest's channel count never moves into the menu: it is a
                fact about them, readable at a glance down the whole table, and
                burying it behind a click would make you open every row to find
                the guest who is in too many channels. Narrow, it gives up its
                column but stays on the row.
            */}
            <td className="hidden px-3 py-2 text-xs whitespace-nowrap text-muted-foreground lg:table-cell">
                {channels}
            </td>

            <td className="px-3 py-2">
                <div className="flex justify-end">
                    {/*
                        One menu per row rather than a row of icons. With two
                        actions the icons were already competing with the role
                        select for the eye, and every action added would have
                        made the table wider instead of the menu longer. An
                        action nobody may take is left out entirely — the same
                        abilities the server checks, asked once here.
                    */}
                    {(member.canManageChannels ||
                        member.canChangeHandle ||
                        member.canImpersonate ||
                        member.canRemove) && (
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                aria-label={t('settings.members.actions_for', {
                                    name: member.name,
                                })}
                                className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <MoreHorizontal className="size-4" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {member.canManageChannels &&
                                    member.channelIds && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                onEditChannels(member)
                                            }
                                        >
                                            <Hash className="size-4" />
                                            {t(
                                                'settings.members.manage_channels',
                                            )}
                                        </DropdownMenuItem>
                                    )}
                                {member.canChangeHandle && (
                                    <DropdownMenuItem
                                        onSelect={() => onEditHandle(member)}
                                    >
                                        <AtSign className="size-4" />
                                        {t('settings.members.change_handle')}
                                    </DropdownMenuItem>
                                )}
                                {/*
                                    Under the two ordinary actions and above
                                    the destructive one, which is where it
                                    belongs on both counts: it is not a change
                                    to this member's row, and it is not
                                    something you undo by clicking again.
                                */}
                                {member.canImpersonate && (
                                    <DropdownMenuItem
                                        onSelect={() => onImpersonate(member)}
                                    >
                                        <VenetianMask className="size-4" />
                                        {t('settings.members.impersonate', {
                                            name: member.name,
                                        })}
                                    </DropdownMenuItem>
                                )}
                                {member.canRemove && (
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => onRemove(member)}
                                    >
                                        <UserMinus className="size-4" />
                                        {t('settings.members.remove')}
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </td>
        </tr>
    );
}

export default function WorkspaceMembers({
    workspaceName,
    workspaceSlug,
    canInvite,
    invitableRoles,
    members,
    roleOptions,
    channelOptions,
}: MembersProps) {
    const [filter, setFilter] = useState('');
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    const [pendingRemoval, setPendingRemoval] =
        useState<WorkspaceMember | null>(null);
    const { t } = useTranslate();
    const [editingChannelsOf, setEditingChannelsOf] =
        useState<WorkspaceMember | null>(null);
    const [editingHandleOf, setEditingHandleOf] =
        useState<WorkspaceMember | null>(null);
    const [pendingImpersonation, setPendingImpersonation] =
        useState<WorkspaceMember | null>(null);
    const [inviting, setInviting] = useState(false);

    /*
     * The same channels the guest dialog ticks, under the names the invite
     * dialog reads them by. Non-DM only, so a channel's name is its label.
     */
    const invitableChannels = useMemo(
        () =>
            channelOptions.map((channel) => ({
                id: channel.id,
                type: channel.type,
                label: channel.name,
            })),
        [channelOptions],
    );

    // The order the server sent, so the role column can restore it.
    const serverOrder = useMemo(
        () => new Map(members.map((member, index) => [member.id, index])),
        [members],
    );

    const needle = filter.trim().toLowerCase();
    const visible = sortMembers(
        members.filter(
            (member) =>
                member.name.toLowerCase().includes(needle) ||
                member.username.toLowerCase().includes(needle),
        ),
        sort,
        serverOrder,
    );

    const toggleSort = (key: SortKey) =>
        setSort((current) =>
            current.key === key
                ? { key, ascending: !current.ascending }
                : { key, ascending: true },
        );

    return (
        <>
            <Head title={t('settings.members.head')} />

            <SettingsSection
                title={t('settings.members.title', {
                    count: members.length,
                })}
                description={t('settings.members.description', {
                    workspace: workspaceName,
                })}
                /*
                    Beside the heading of the list itself. Who is here and who
                    is on their way in is one question, and answering the second
                    half of it meant leaving this page for the chat sidebar —
                    which is where you look at the list, not where you manage
                    it.
                */
                actions={
                    canInvite && (
                        <Button type="button" onClick={() => setInviting(true)}>
                            <UserPlus className="size-4" />
                            {t('settings.members.invite')}
                        </Button>
                    )
                }
            >
                {members.length >= FILTER_FROM && (
                    <div className="relative max-w-sm">
                        <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder={t(
                                'settings.members.filter_placeholder',
                            )}
                            className="pl-8"
                            aria-label={t('settings.members.filter')}
                        />
                    </div>
                )}

                {/*
                    The table scrolls inside its own box rather than pushing the
                    page sideways: a role select and a status somebody typed are
                    both as wide as they are.

                    Below `lg` it is a narrower table rather than the same one
                    behind a scrollbar, as on the channel list. Seven columns do
                    not fit an iPad held upright, and of the seven the role
                    select and the status have to stay: one is the only control
                    on the row, the other is why you came. So the three that
                    read as facts about somebody — their handle, when they
                    joined, how many channels they are in — move under the name.
                */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-xl border-collapse lg:min-w-3xl">
                        <thead>
                            <tr className="bg-muted/40">
                                <SortableHeader
                                    label={t('settings.members.column_name')}
                                    column="name"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label={t(
                                        'settings.members.column_username',
                                    )}
                                    column="username"
                                    sort={sort}
                                    onSort={toggleSort}
                                    className="hidden lg:table-cell"
                                />
                                <SortableHeader
                                    label={t('settings.members.column_role')}
                                    column="role"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                {/*
                                    Not sortable: a status is a sentence, and
                                    ordering people alphabetically by what they
                                    happen to be doing answers no question.
                                */}
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                >
                                    {t('settings.members.column_status')}
                                </th>
                                <SortableHeader
                                    label={t('settings.members.column_joined')}
                                    column="joinedAt"
                                    sort={sort}
                                    onSort={toggleSort}
                                    className="hidden lg:table-cell"
                                />
                                <th
                                    scope="col"
                                    className="hidden px-3 py-2 text-left text-xs font-medium text-muted-foreground lg:table-cell"
                                >
                                    {t('settings.members.column_channels')}
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">
                                        {t('settings.members.column_actions')}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((member) => (
                                <MemberRow
                                    key={member.id}
                                    member={member}
                                    roleOptions={roleOptions}
                                    onRemove={setPendingRemoval}
                                    onEditChannels={setEditingChannelsOf}
                                    onEditHandle={setEditingHandleOf}
                                    onImpersonate={setPendingImpersonation}
                                />
                            ))}
                            {visible.length === 0 && (
                                <tr className="border-t">
                                    <td
                                        colSpan={7}
                                        className="px-3 py-6 text-center text-sm text-muted-foreground"
                                    >
                                        {t('settings.members.none_found')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </SettingsSection>

            {/*
                The same dialog the chat sidebar opens, so an invitation sent
                from here asks the same questions and lands in the same place.
                Only rendered where it may be used: it posts to an endpoint that
                would refuse anybody else.
            */}
            {canInvite && (
                <InvitePeopleDialog
                    workspace={{
                        name: workspaceName,
                        slug: workspaceSlug,
                        invitableRoles,
                    }}
                    channels={invitableChannels}
                    open={inviting}
                    onOpenChange={setInviting}
                />
            )}

            <GuestChannelsDialog
                guest={
                    editingChannelsOf && editingChannelsOf.channelIds
                        ? {
                              id: editingChannelsOf.id,
                              name: editingChannelsOf.name,
                              channelIds: editingChannelsOf.channelIds,
                          }
                        : null
                }
                channels={channelOptions}
                onOpenChange={(next) => {
                    if (!next) {
                        setEditingChannelsOf(null);
                    }
                }}
            />

            <MemberHandleDialog
                member={editingHandleOf}
                onOpenChange={(next) => {
                    if (!next) {
                        setEditingHandleOf(null);
                    }
                }}
            />

            {/*
                Asked before it happens, unlike every other action on this page.
                The rest change a row and say so in a flash message; this one
                puts you inside somebody's private messages, where a misclick is
                not something an undo can take back — and where whatever you type
                next goes out under their name.
            */}
            <AlertDialog
                open={pendingImpersonation !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setPendingImpersonation(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('settings.members.impersonate_question', {
                                name: pendingImpersonation?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('settings.members.impersonate_explanation', {
                                name: pendingImpersonation?.name ?? '',
                            })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('settings.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                const member = pendingImpersonation;

                                if (member) {
                                    /*
                                        A full load rather than an Inertia visit,
                                        for the reason the bar's stop button
                                        does it: what comes back belongs to
                                        somebody else, and every prop and socket
                                        subscription this page is holding is the
                                        impersonator's.
                                    */
                                    router.post(
                                        impersonateMember.url(member.id),
                                        {},
                                        {
                                            onSuccess: () =>
                                                window.location.reload(),
                                        },
                                    );
                                }
                            }}
                        >
                            {t('settings.members.impersonate_confirm')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={pendingRemoval !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setPendingRemoval(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('settings.members.remove_question', {
                                name: pendingRemoval?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('settings.members.remove_explanation', {
                                name: pendingRemoval?.name ?? '',
                            })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('settings.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                const member = pendingRemoval;

                                if (member) {
                                    router.delete(removeMember.url(member.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {t('settings.members.remove_confirm')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
