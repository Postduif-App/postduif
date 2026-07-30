import { Link } from '@inertiajs/react';
import { Hash, Lock, MessageSquare, Plus, Search } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import type { ChannelSummary, ChatWorkspace } from '@/types/chat';

interface ChannelSidebarProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeChannelId: number;
    onOpenSearch: () => void;
    onCreateChannel: () => void;
}

function ChannelIcon({
    type,
    className,
}: {
    type: ChannelSummary['type'];
    className?: string;
}) {
    if (type === 'private') {
        return <Lock className={className} />;
    }

    if (type === 'dm') {
        return <MessageSquare className={className} />;
    }

    return <Hash className={className} />;
}

function ChannelLink({
    workspaceSlug,
    channel,
    active,
}: {
    workspaceSlug: string;
    channel: ChannelSummary;
    active: boolean;
}) {
    return (
        <Link
            href={show({ workspace: workspaceSlug, channel: channel.id })}
            // No prefetch: opening a channel marks it read, and prefetching
            // fires that same request on hover — so merely sweeping the mouse
            // down the sidebar would clear every badge.
            className={cn(
                'group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
                active
                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
            )}
        >
            <ChannelIcon
                type={channel.type}
                className="size-4 shrink-0 opacity-70"
            />
            <span
                className={cn(
                    'truncate',
                    // Unread reads as weight, not as a second badge: the eye
                    // finds a bold row faster than it counts numbers.
                    channel.unreadCount > 0 &&
                        !active &&
                        'font-semibold text-sidebar-foreground',
                )}
            >
                {channel.label}
            </span>

            {channel.mentionCount > 0 ? (
                <span className="ml-auto shrink-0 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                    {channel.mentionCount}
                </span>
            ) : (
                channel.unreadCount > 0 &&
                !active && (
                    <span className="ml-auto size-1.5 shrink-0 rounded-full bg-sidebar-foreground/60" />
                )
            )}
        </Link>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="px-2 pt-4 pb-1 text-xs font-semibold tracking-wide text-sidebar-foreground/50 uppercase">
            {children}
        </h2>
    );
}

export function ChannelSidebar({
    workspace,
    channels,
    directMessages,
    activeChannelId,
    onOpenSearch,
    onCreateChannel,
}: ChannelSidebarProps) {
    const [filter, setFilter] = useState('');

    const matches = (channel: ChannelSummary) =>
        channel.label.toLowerCase().includes(filter.trim().toLowerCase());

    const visibleChannels = channels.filter(matches);
    const visibleDirects = directMessages.filter(matches);

    return (
        <aside className="flex h-full w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
            <div className="flex h-14 items-center gap-2 border-b border-sidebar-border px-4">
                <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-bold text-primary-foreground">
                    {workspace.name.slice(0, 2).toUpperCase()}
                </div>
                <span className="truncate font-semibold">{workspace.name}</span>
            </div>

            <div className="space-y-2 p-2">
                <Button
                    variant="outline"
                    size="sm"
                    className="w-full justify-start gap-2 font-normal text-muted-foreground"
                    onClick={onOpenSearch}
                >
                    <Search className="size-4" />
                    Zoeken
                    <kbd className="ml-auto rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                        ⌘K
                    </kbd>
                </Button>

                <input
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder="Filter kanalen…"
                    className="w-full rounded-md border border-sidebar-border bg-transparent px-2 py-1.5 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>

            <ScrollArea className="flex-1 px-2 pb-4">
                <SectionHeading>Kanalen</SectionHeading>
                <div className="space-y-0.5">
                    {visibleChannels.map((channel) => (
                        <ChannelLink
                            key={channel.id}
                            workspaceSlug={workspace.slug}
                            channel={channel}
                            active={channel.id === activeChannelId}
                        />
                    ))}
                    {visibleChannels.length === 0 && (
                        <p className="px-2 py-1 text-sm text-muted-foreground">
                            Geen kanalen
                        </p>
                    )}
                </div>

                <SectionHeading>Directe berichten</SectionHeading>
                <div className="space-y-0.5">
                    {visibleDirects.map((channel) => (
                        <ChannelLink
                            key={channel.id}
                            workspaceSlug={workspace.slug}
                            channel={channel}
                            active={channel.id === activeChannelId}
                        />
                    ))}
                    {visibleDirects.length === 0 && (
                        <p className="px-2 py-1 text-sm text-muted-foreground">
                            Nog geen gesprekken
                        </p>
                    )}
                </div>

                <button
                    type="button"
                    onClick={onCreateChannel}
                    className="mt-4 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
                >
                    <Plus className="size-4" />
                    Kanaal toevoegen
                </button>
            </ScrollArea>
        </aside>
    );
}
