import { Head, Link, router, usePage } from '@inertiajs/react';
import { MessageSquare, Plus, Ticket as TicketIcon } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { CreateTicketDialog } from '@/components/chat/create-ticket-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { TicketPanel } from '@/components/chat/ticket-panel';
import {
    ALL_STATUSES,
    OPEN_STATUSES,
    TICKET_PRIORITY,
    TICKET_STATUS,
    TicketPriorityLabel,
    TicketStatusBadge,
} from '@/components/chat/ticket-status';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useInitials } from '@/hooks/use-initials';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { index as ticketsIndex } from '@/routes/chat/tickets';
import type { Auth } from '@/types';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelMember,
    ChannelSummary,
    ChatWorkspace,
    OpenTicket,
    TicketPriority,
    TicketStatus,
    TicketSummary,
} from '@/types/chat';

const DATE_FORMAT = new Intl.DateTimeFormat('nl-NL', { dateStyle: 'short' });

/** A row here is a board row plus the channel it came from. */
interface WorkspaceTicket extends TicketSummary {
    channelLabel: string | null;
}

/** The open ticket, plus what the panel needs of the channel it sits in. */
interface OpenWorkspaceTicket extends OpenTicket {
    channelId: number;
    channelLabel: string | null;
    channelMembers: ChannelMember[];
}

interface Filters {
    status: TicketStatus | null;
    priority: TicketPriority | null;
    assignee: number | null;
    channel: number | null;
    open: boolean;
}

interface WorkspaceTicketsProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    rows: WorkspaceTicket[];
    /** Counted over everything visible, so the buttons keep their totals. */
    counts: Record<string, number>;
    ticketChannels: { id: number; label: string; canCreate: boolean }[];
    /** Every tag on a channel this member can see, for the sidebar filter. */
    workspaceTags: string[];
    /** Channels that were put away, for whoever may take them back out. */
    archivedChannels: ArchivedChannel[];
    /** The groups this member arranged for themselves. */
    sections: ChannelSectionRow[];
    filters: Filters;
    /** The ticket named by ?ticket= in the URL, or null. */
    ticket: OpenWorkspaceTicket | null;
}

/**
 * Every ticket in the workspace, in the same shell as a channel.
 *
 * The filters live in the query string rather than in component state, unlike
 * the per-channel board's: this list is where somebody goes to find work, and
 * "alle urgente van Anna" is exactly the kind of thing you send to a colleague
 * or keep in a tab.
 */
export default function WorkspaceTickets({
    workspace,
    channels,
    directMessages,
    activeThreads,
    rows,
    counts,
    ticketChannels,
    filters,
    ticket,
    workspaceTags,
    archivedChannels,
    sections,
}: WorkspaceTicketsProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();
    const [searchOpen, setSearchOpen] = useState(false);

    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [createTicketOpen, setCreateTicketOpen] = useState(false);
    /*
        Bumped every time the dialog opens and used as its key, the same way the
        conversation does it: a fresh mount is what clears the fields, so no
        effect has to write state when it opens and no half-typed ticket is left
        over from last time.
    */
    const [ticketFormKey, setTicketFormKey] = useState(0);

    // Filtering is open to every channel on the page; opening a ticket is not —
    // that needs membership and the channel's own ticket policy.
    const creatable = ticketChannels.filter((channel) => channel.canCreate);

    useSessionGuard();

    /**
     * Change one filter and keep the rest.
     *
     * Values that fall back to their default are dropped from the URL rather
     * than written out as empty, so an unfiltered list has a clean address
     * worth keeping.
     */
    const go = (changes: Partial<Filters>, openTicket?: number | null) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        // Which ticket is open travels in the URL alongside the filters, the
        // same way it does on a channel's board — so a ticket found here can be
        // linked to, filters and all.
        const open = openTicket === undefined ? ticket?.number : openTicket;

        if (open) {
            query.ticket = open;
        }

        if (next.status) {
            query.status = next.status;
        }

        if (next.priority) {
            query.priority = next.priority;
        }

        if (next.assignee) {
            query.assignee = next.assignee;
        }

        if (next.channel) {
            query.channel = next.channel;
        }

        // Outstanding-only is the default, so only its opposite is written.
        if (!next.open) {
            query.open = '0';
        }

        router.visit(ticketsIndex(workspace.slug, { query }), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const count = (status: TicketStatus) => counts[status] ?? 0;
    const outstanding = OPEN_STATUSES.reduce(
        (total, status) => total + count(status),
        0,
    );

    const userMenu = (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:outline-none">
                <Avatar className="size-8 shrink-0">
                    {/*
                        Above the fallback rather than instead of it: Radix
                        draws the initials until the picture has loaded, and
                        keeps them if it never does.
                    */}
                    {auth.avatarUrl && (
                        <AvatarImage src={auth.avatarUrl} alt="" />
                    )}
                    <AvatarFallback className="text-xs font-semibold">
                        {getInitials(auth.user.name)}
                    </AvatarFallback>
                </Avatar>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {auth.user.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {auth.user.status_text
                            ? `${auth.user.status_emoji ?? ''} ${auth.user.status_text}`.trim()
                            : 'Status instellen'}
                    </span>
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-56">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title="Tickets" />

            <ChannelSidebar
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                ticketsActive
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={userMenu}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <TicketIcon className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            Tickets
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            Alles uit de kanalen die je kunt zien
                        </p>
                    </div>

                    {creatable.length > 0 && (
                        <Button
                            size="sm"
                            className="ml-auto"
                            onClick={() => {
                                setTicketFormKey((key) => key + 1);
                                setCreateTicketOpen(true);
                            }}
                        >
                            <Plus className="size-4" />
                            Nieuw ticket
                        </Button>
                    )}
                </header>

                <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                    <FilterPill
                        selected={filters.open && filters.status === null}
                        onClick={() => go({ open: true, status: null })}
                    >
                        Openstaand
                        <Total value={outstanding} />
                    </FilterPill>

                    {ALL_STATUSES.map((status) => (
                        <FilterPill
                            key={status}
                            selected={filters.status === status}
                            onClick={() =>
                                go({
                                    status:
                                        filters.status === status
                                            ? null
                                            : status,
                                })
                            }
                        >
                            {TICKET_STATUS[status].label}
                            <Total value={count(status)} />
                        </FilterPill>
                    ))}

                    <FilterPill
                        selected={!filters.open && filters.status === null}
                        onClick={() => go({ open: false, status: null })}
                    >
                        Alles
                    </FilterPill>

                    <div className="ml-auto flex items-center gap-2">
                        <Select
                            label="Prioriteit"
                            value={filters.priority ?? ''}
                            onChange={(value) =>
                                go({
                                    priority: (value as TicketPriority) || null,
                                })
                            }
                            options={Object.entries(TICKET_PRIORITY).map(
                                ([value, meta]) => ({
                                    value,
                                    label: meta.label,
                                }),
                            )}
                        />
                        <Select
                            label="Kanaal"
                            value={
                                filters.channel ? String(filters.channel) : ''
                            }
                            onChange={(value) =>
                                go({ channel: value ? Number(value) : null })
                            }
                            options={ticketChannels.map((channel) => ({
                                value: String(channel.id),
                                label: `#${channel.label}`,
                            }))}
                        />
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {ticketChannels.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            Nog geen enkel kanaal houdt tickets bij. Zet tickets
                            aan in de instellingen van een kanaal.
                        </p>
                    ) : rows.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            Geen tickets die hieraan voldoen.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {rows.map((row) => (
                                <li key={row.id}>
                                    {/*
                                        Opens the panel here rather than
                                        jumping to the channel: somebody
                                        working through this list is going
                                        through several channels' work at
                                        once, and being thrown into a
                                        conversation loses their place in it.
                                        The panel links onward for whoever
                                        does want the conversation.
                                    */}
                                    <button
                                        type="button"
                                        onClick={() => go({}, row.number)}
                                        aria-current={
                                            row.number === ticket?.number
                                        }
                                        className={cn(
                                            'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50',
                                            row.number === ticket?.number &&
                                                'bg-muted',
                                        )}
                                    >
                                        <span className="w-10 shrink-0 pt-0.5 text-xs text-muted-foreground tabular-nums">
                                            #{row.number}
                                        </span>

                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-2">
                                                <span className="truncate text-sm font-medium">
                                                    {row.title}
                                                </span>
                                                <TicketPriorityLabel
                                                    priority={row.priority}
                                                />
                                            </span>
                                            <span className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                {row.channelLabel && (
                                                    <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-medium">
                                                        #{row.channelLabel}
                                                    </span>
                                                )}
                                                <span className="truncate">
                                                    {row.opener?.name ??
                                                        'Onbekend'}
                                                    {row.createdAt &&
                                                        ` · ${DATE_FORMAT.format(new Date(row.createdAt))}`}
                                                </span>
                                                {row.commentCount > 0 && (
                                                    <span className="flex shrink-0 items-center gap-1">
                                                        <MessageSquare className="size-3" />
                                                        {row.commentCount}
                                                    </span>
                                                )}
                                            </span>
                                        </span>

                                        <span className="flex shrink-0 flex-col items-end gap-1">
                                            <TicketStatusBadge
                                                status={row.status}
                                            />
                                            {row.assignee && (
                                                <span className="text-xs text-muted-foreground">
                                                    {row.assignee.name}
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {/*
                    The way onward, promised by the comment on the row above:
                    the panel handles the ticket, the channel is where it is
                    being talked about.
                */}
                {ticket && (
                    <div className="border-t px-4 py-2 text-xs text-muted-foreground">
                        <Link
                            href={show(
                                {
                                    workspace: workspace.slug,
                                    channel: ticket.channelId,
                                },
                                {
                                    query: {
                                        view: 'tickets',
                                        ticket: ticket.number,
                                    },
                                },
                            )}
                            className="font-medium text-primary hover:underline"
                        >
                            Open #{ticket.number} in
                            {ticket.channelLabel
                                ? ` #${ticket.channelLabel}`
                                : ' het kanaal'}
                        </Link>
                    </div>
                )}
            </main>

            {ticket && (
                <TicketPanel
                    workspace={workspace}
                    channel={{
                        id: ticket.channelId,
                        members: ticket.channelMembers,
                    }}
                    ticket={ticket}
                    onClose={() => go({}, null)}
                />
            )}

            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />

            <CreateTicketDialog
                key={ticketFormKey}
                workspace={workspace}
                channels={creatable}
                source={null}
                // A guest does not get the field: their own problem is always
                // urgent, so the value only means something once one person
                // weighs all the tickets against each other.
                canPrioritise={auth.workspaceRole !== 'guest'}
                open={createTicketOpen}
                onOpenChange={setCreateTicketOpen}
            />

            <SearchDialog
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                actions={{
                    onCreateChannel: workspace.canCreateChannel
                        ? () => setCreateOpen(true)
                        : undefined,
                    onStartDirectMessage: workspace.canStartDirectMessage
                        ? () => setDirectOpen(true)
                        : undefined,
                    onInvitePeople: workspace.canInvite
                        ? () => setInviteOpen(true)
                        : undefined,
                    onBroadcast: workspace.canBroadcastToChannels
                        ? () => setBroadcastOpen(true)
                        : undefined,
                }}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />

            <CreateChannelDialog
                workspace={workspace}
                open={createOpen}
                onOpenChange={setCreateOpen}
            />

            {workspace.canStartDirectMessage && (
                <NewDirectMessageDialog
                    workspace={workspace}
                    open={directOpen}
                    onOpenChange={setDirectOpen}
                />
            )}

            {workspace.canInvite && (
                <InvitePeopleDialog
                    workspace={workspace}
                    channels={channels.filter((row) => row.type !== 'dm')}
                    open={inviteOpen}
                    onOpenChange={setInviteOpen}
                />
            )}
        </div>
    );
}

function FilterPill({
    selected,
    onClick,
    children,
}: {
    selected: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onClick}
            className={cn(
                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                selected
                    ? 'border-primary/50 bg-primary/10 text-foreground'
                    : 'text-muted-foreground hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

function Total({ value }: { value: number }) {
    return <span className="ml-1.5 tabular-nums opacity-70">{value}</span>;
}

/**
 * A plain select, not the styled one from the ticket panel.
 *
 * This is a filter bar, where an empty choice means "geen filter" and has to be
 * pickable — and the Radix select the panel uses cannot hold an empty value.
 */
function Select({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            className="rounded-md border bg-background px-2 py-1 text-xs text-muted-foreground focus-visible:ring-2 focus-visible:outline-none"
        >
            <option value="">{label}: alle</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}
