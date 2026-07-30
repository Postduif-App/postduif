import { Head, router, usePage } from '@inertiajs/react';
import { Hash, Lock, MessageSquare, Users } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { Composer } from '@/components/chat/composer';
import { MessageList } from '@/components/chat/message-list';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { ulid } from '@/lib/ulid';
import type { Auth } from '@/types';
import type {
    ActiveChannel,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
} from '@/types/chat';

interface ChatShowProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    channel: ActiveChannel;
    messages: ChatMessage[];
}

function ChannelHeaderIcon({ type }: { type: ActiveChannel['type'] }) {
    const className = 'size-4 text-muted-foreground';

    if (type === 'private') {
        return <Lock className={className} />;
    }

    if (type === 'dm') {
        return <MessageSquare className={className} />;
    }

    return <Hash className={className} />;
}

export default function ChatShow({
    workspace,
    channels,
    directMessages,
    channel,
    messages,
}: ChatShowProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();

    const [searchOpen, setSearchOpen] = useState(false);
    const [pending, setPending] = useState<ChatMessage[]>([]);

    // Server data is the source of truth. A draft stays on screen only until
    // its server-persisted twin (same client-minted ULID) shows up, which is
    // what keeps the message from appearing twice.
    const visibleMessages = [
        ...messages,
        ...pending.filter(
            (draft) => !messages.some((message) => message.id === draft.id),
        ),
    ];

    const send = useCallback(
        (body: string) => {
            const draft: ChatMessage = {
                id: ulid(),
                body,
                createdAt: new Date().toISOString(),
                editedAt: null,
                replyCount: 0,
                author: { id: auth.user.id, name: auth.user.name },
                reactions: [],
                pending: true,
            };

            setPending((current) => [...current, draft]);

            router.post(
                `/w/${workspace.slug}/c/${channel.id}/messages`,
                { id: draft.id, body: draft.body },
                {
                    preserveScroll: true,
                    onFinish: () =>
                        setPending((current) =>
                            current.filter((item) => item.id !== draft.id),
                        ),
                },
            );
        },
        [auth.user.id, auth.user.name, channel.id, workspace.slug],
    );

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setSearchOpen((open) => !open);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title={channel.label} />

            <ChannelSidebar
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                activeChannelId={channel.id}
                onOpenSearch={() => setSearchOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <ChannelHeaderIcon type={channel.type} />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {channel.label}
                        </h1>
                        {channel.topic && (
                            <p className="truncate text-xs text-muted-foreground">
                                {channel.topic}
                            </p>
                        )}
                    </div>

                    <span className="ml-auto flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Users className="size-3.5" />
                        {channel.memberCount}
                    </span>

                    <DropdownMenu>
                        <DropdownMenuTrigger className="rounded-full focus-visible:ring-2 focus-visible:outline-none">
                            <Avatar className="size-8">
                                <AvatarFallback className="text-xs font-semibold">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>

                <MessageList messages={visibleMessages} />

                <Composer
                    placeholder={
                        channel.isMember
                            ? `Bericht aan ${channel.type === 'dm' ? channel.label : '#' + channel.label}`
                            : 'Word lid van dit kanaal om te reageren'
                    }
                    disabled={!channel.isMember}
                    onSend={send}
                />
            </main>

            <SearchDialog
                workspace={workspace}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />
        </div>
    );
}
