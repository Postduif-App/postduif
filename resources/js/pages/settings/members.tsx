import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Hash,
    MoreHorizontal,
    Search,
    UserMinus,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { GuestChannelsDialog } from '@/components/guest-channels-dialog';
import type { ChannelOption } from '@/components/guest-channels-dialog';
import Heading from '@/components/heading';
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
import { buttonVariants } from '@/components/ui/button';
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
    update as updateRole,
} from '@/routes/workspace/members';
import type { Availability } from '@/types/auth';

interface Option {
    value: string;
    label: string;
}

interface WorkspaceMember {
    id: number;
    name: string;
    username: string;
    role: string;
    roleLabel: string;
    joinedAt: string | null;
    statusEmoji: string | null;
    statusText: string | null;
    availability: Availability;
    /** Guests only: the channels they were let into. Null for everyone else. */
    channelIds: number[] | null;
    canChangeRole: boolean;
    canRemove: boolean;
    canManageChannels: boolean;
}

interface MembersProps {
    workspaceName: string;
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
 * roles again: that ranking lives in WorkspaceRole::rank(), and a second copy
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
}: {
    label: string;
    column: SortKey;
    sort: Sort;
    onSort: (key: SortKey) => void;
}) {
    const active = sort.key === column;

    return (
        <th
            scope="col"
            className="px-3 py-2 text-left"
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
}: {
    member: WorkspaceMember;
    roleOptions: Option[];
    onRemove: (member: WorkspaceMember) => void;
    onEditChannels: (member: WorkspaceMember) => void;
}) {
    const getInitials = useInitials();
    const { t } = useTranslate();
    const formats = useFormats();

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
                    <span className="min-w-0 truncate text-sm font-medium">
                        {member.name}
                    </span>
                </div>
            </td>

            <td className="px-3 py-2 text-sm text-muted-foreground">
                @{member.username}
            </td>

            <td className="px-3 py-2">
                {member.canChangeRole ? (
                    <Select
                        value={member.role}
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
                                    value={option.value}
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
                            // accent. A guest is set apart the other way:
                            // outlined, because it says "external" rather than
                            // "elevated".
                            member.role === 'member' &&
                                'bg-muted text-muted-foreground',
                            member.role === 'guest' &&
                                'border border-amber-500/40 text-amber-700 dark:text-amber-400',
                            (member.role === 'owner' ||
                                member.role === 'admin') &&
                                'bg-primary/10 text-primary',
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

            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                {member.joinedAt
                    ? formats.mediumDate.format(new Date(member.joinedAt))
                    : '—'}
            </td>

            {/*
                The guest's channel count stays a column rather than moving into
                the menu: it is a fact about them, readable at a glance down the
                whole table, and burying it behind a click would make you open
                every row to find the guest who is in too many channels.
            */}
            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                {member.channelIds ? (
                    <span className="inline-flex items-center gap-1">
                        <Hash className="size-3" />
                        {member.channelIds.length}
                    </span>
                ) : (
                    // For everyone else it is the whole workspace, and a number
                    // here would suggest a boundary that is not there.
                    <span className="opacity-60">
                        {t('settings.members.all_channels')}
                    </span>
                )}
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
                    {(member.canManageChannels || member.canRemove) && (
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

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.members.title', {
                        count: members.length,
                    })}
                    description={t('settings.members.description', {
                        workspace: workspaceName,
                    })}
                />

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
                */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-3xl border-collapse">
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
                                />
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
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
            </div>

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
