import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Archive,
    ArchiveRestore,
    Hash,
    Lock,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { show as showChannel } from '@/routes/chat';
import { archive } from '@/routes/chat/channels';
import type { TranslationKey } from '@/types/translations';

/** One channel as this table lists it. */
interface WorkspaceChannel {
    id: number;
    name: string;
    topic: string | null;
    type: 'public' | 'private';
    layout: string;
    postingPolicy: string;
    ticketPolicy: string;
    memberCount: number;
    messageCount: number;
    ticketCount: number;
    linkCount: number;
    webhookCount: number;
    tags: string[];
    /** Null where the person who made it has since left the workspace. */
    createdBy: string | null;
    createdAt: string | null;
    lastMessageAt: string | null;
    /** Null for a channel that is still in use. */
    archivedAt: string | null;
    canArchive: boolean;
    canOpen: boolean;
}

interface WorkspaceChannelsProps {
    workspaceName: string;
    workspaceSlug: string;
    channels: WorkspaceChannel[];
}

/** Below this a filter box is clutter rather than help. */
const FILTER_FROM = 8;

type SortKey = 'name' | 'members' | 'messages' | 'lastMessageAt' | 'createdAt';

interface Sort {
    key: SortKey;
    ascending: boolean;
}

/**
 * Busiest first. "Waar gebeurt het" is what somebody opens this page with, and
 * alphabetical order answers a question nobody asked — they already know the
 * name of the channel they were looking for.
 */
const DEFAULT_SORT: Sort = { key: 'lastMessageAt', ascending: false };

const POSTING_POLICY_KEY: Record<string, TranslationKey> = {
    everyone: 'enums.channel-posting-policy.label.Everyone',
    admins: 'enums.channel-posting-policy.label.Admins',
};

/**
 * Sorting happens in the browser: one workspace's channels are all on the page
 * already, so a round trip per click would cost a page load to reorder rows
 * that are sitting here.
 */
function sortChannels(
    channels: WorkspaceChannel[],
    sort: Sort,
): WorkspaceChannel[] {
    const compare = (a: WorkspaceChannel, b: WorkspaceChannel): number => {
        switch (sort.key) {
            case 'name':
                return a.name.localeCompare(b.name, 'nl');
            case 'members':
                return a.memberCount - b.memberCount;
            case 'messages':
                return a.messageCount - b.messageCount;
            case 'lastMessageAt':
            case 'createdAt': {
                const left =
                    sort.key === 'createdAt' ? a.createdAt : a.lastMessageAt;
                const right =
                    sort.key === 'createdAt' ? b.createdAt : b.lastMessageAt;

                // Missing sorts last whichever way round: a channel nobody has
                // said anything in is not "the oldest conversation".
                if (!left || !right) {
                    return left ? -1 : right ? 1 : 0;
                }

                return left.localeCompare(right);
            }
        }
    };

    return [...channels].sort((a, b) =>
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

export default function WorkspaceChannels({
    workspaceName,
    workspaceSlug,
    channels,
}: WorkspaceChannelsProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    const [filter, setFilter] = useState('');
    /** Archived ones are out of the way until somebody goes looking for them. */
    const [showArchived, setShowArchived] = useState(false);

    const archivedCount = channels.filter(
        (channel) => channel.archivedAt !== null,
    ).length;

    const rows = useMemo(() => {
        const needle = filter.trim().toLowerCase();

        return sortChannels(
            channels
                .filter(
                    (channel) => showArchived || channel.archivedAt === null,
                )
                .filter(
                    (channel) =>
                        needle === '' ||
                        channel.name.toLowerCase().includes(needle) ||
                        (channel.topic ?? '').toLowerCase().includes(needle) ||
                        channel.tags.some((tag) =>
                            tag.toLowerCase().includes(needle),
                        ),
                ),
            sort,
        );
    }, [channels, filter, showArchived, sort]);

    const toggleSort = (key: SortKey) =>
        setSort((current) =>
            current.key === key
                ? { key, ascending: !current.ascending }
                : {
                      key,
                      // Names read best A–Z; everything else here is a number or
                      // a moment, where "most" and "newest" are what you want
                      // first.
                      ascending: key === 'name',
                  },
        );

    return (
        <>
            <Head title={t('settings.channels.title')} />

            <SettingsSection
                title={t('settings.channels.title')}
                description={t('settings.channels.description', {
                    workspace: workspaceName,
                })}
                actions={
                    archivedCount > 0 && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setShowArchived((current) => !current)
                            }
                            aria-pressed={showArchived}
                        >
                            {showArchived
                                ? t('settings.channels.hide_archived')
                                : tChoice(
                                      'settings.channels.show_archived',
                                      archivedCount,
                                  )}
                        </Button>
                    )
                }
            >
                {channels.length >= FILTER_FROM && (
                    <Input
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        placeholder={t('settings.channels.filter')}
                        aria-label={t('settings.channels.filter')}
                        className="max-w-xs"
                    />
                )}

                {/*
                    The table scrolls inside its own box rather than pushing the
                    page sideways — the same choice the member list makes, and
                    for the same reason: a row of counts and a topic somebody
                    typed are both as wide as they are.
                */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-3xl border-collapse">
                        <thead>
                            <tr className="bg-muted/40">
                                <SortableHeader
                                    label={t('settings.channels.column_name')}
                                    column="name"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label={t(
                                        'settings.channels.column_members',
                                    )}
                                    column="members"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label={t(
                                        'settings.channels.column_messages',
                                    )}
                                    column="messages"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-left text-xs font-medium text-muted-foreground"
                                >
                                    {t('settings.channels.column_settings')}
                                </th>
                                <SortableHeader
                                    label={t('settings.channels.column_last')}
                                    column="lastMessageAt"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <SortableHeader
                                    label={t(
                                        'settings.channels.column_created',
                                    )}
                                    column="createdAt"
                                    sort={sort}
                                    onSort={toggleSort}
                                />
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">
                                        {t('settings.channels.column_actions')}
                                    </span>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((channel) => (
                                <tr
                                    key={channel.id}
                                    className={cn(
                                        'border-t',
                                        // Dimmed rather than hidden: it is
                                        // still a channel, and everything the
                                        // row says about it is still true.
                                        channel.archivedAt !== null &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <td className="px-3 py-2">
                                        <div className="flex items-center gap-1.5">
                                            {channel.type === 'private' ? (
                                                <Lock className="size-3.5 shrink-0 opacity-60" />
                                            ) : (
                                                <Hash className="size-3.5 shrink-0 opacity-60" />
                                            )}
                                            {channel.canOpen ? (
                                                <Link
                                                    href={showChannel.url({
                                                        workspace:
                                                            workspaceSlug,
                                                        channel: channel.id,
                                                    })}
                                                    className="font-medium hover:underline"
                                                >
                                                    {channel.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">
                                                    {channel.name}
                                                </span>
                                            )}
                                            {channel.archivedAt !== null && (
                                                <span className="rounded-full border px-1.5 text-[10px]">
                                                    {t(
                                                        'settings.channels.archived',
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        {channel.topic && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {channel.topic}
                                            </p>
                                        )}
                                    </td>

                                    <td className="px-3 py-2 text-sm tabular-nums">
                                        {channel.memberCount}
                                    </td>
                                    <td className="px-3 py-2 text-sm tabular-nums">
                                        {channel.messageCount}
                                    </td>

                                    {/*
                                        The things that are true about a channel
                                        but not worth a column each: who may
                                        post, whether it keeps tickets, what
                                        hangs off it.
                                    */}
                                    <td className="px-3 py-2 text-xs text-muted-foreground">
                                        <div className="flex flex-wrap gap-1">
                                            <span className="rounded border px-1.5 py-0.5">
                                                {t(
                                                    POSTING_POLICY_KEY[
                                                        channel.postingPolicy
                                                    ] ??
                                                        'settings.channels.unknown',
                                                )}
                                            </span>
                                            {channel.ticketCount > 0 && (
                                                <span className="rounded border px-1.5 py-0.5">
                                                    {tChoice(
                                                        'settings.channels.tickets',
                                                        channel.ticketCount,
                                                    )}
                                                </span>
                                            )}
                                            {channel.linkCount > 0 && (
                                                <span className="rounded border px-1.5 py-0.5">
                                                    {tChoice(
                                                        'settings.channels.links',
                                                        channel.linkCount,
                                                    )}
                                                </span>
                                            )}
                                            {channel.webhookCount > 0 && (
                                                <span className="rounded border px-1.5 py-0.5">
                                                    {tChoice(
                                                        'settings.channels.webhooks',
                                                        channel.webhookCount,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </td>

                                    <td className="px-3 py-2 text-sm whitespace-nowrap">
                                        {channel.lastMessageAt
                                            ? formats.shortDateTime.format(
                                                  new Date(
                                                      channel.lastMessageAt,
                                                  ),
                                              )
                                            : t('settings.channels.never')}
                                    </td>

                                    <td className="px-3 py-2 text-sm whitespace-nowrap">
                                        {channel.createdAt
                                            ? formats.shortDate.format(
                                                  new Date(channel.createdAt),
                                              )
                                            : '—'}
                                        {channel.createdBy && (
                                            <p className="text-xs text-muted-foreground">
                                                {channel.createdBy}
                                            </p>
                                        )}
                                    </td>

                                    <td className="px-3 py-2 text-right">
                                        {channel.canArchive && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="gap-1.5"
                                                onClick={() =>
                                                    router.post(
                                                        archive.url({
                                                            workspace:
                                                                workspaceSlug,
                                                            channel: channel.id,
                                                        }),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {channel.archivedAt !== null ? (
                                                    <>
                                                        <ArchiveRestore className="size-3.5" />
                                                        {t(
                                                            'settings.channels.unarchive',
                                                        )}
                                                    </>
                                                ) : (
                                                    <>
                                                        <Archive className="size-3.5" />
                                                        {t(
                                                            'settings.channels.archive',
                                                        )}
                                                    </>
                                                )}
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}

                            {rows.length === 0 && (
                                <tr className="border-t">
                                    <td
                                        colSpan={7}
                                        className="px-3 py-6 text-center text-sm text-muted-foreground"
                                    >
                                        {t('settings.channels.none')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </SettingsSection>
        </>
    );
}
