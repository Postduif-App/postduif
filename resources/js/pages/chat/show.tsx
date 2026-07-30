import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { Conversation } from '@/components/chat/conversation';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import type { Auth } from '@/types';
import type {
    ActiveChannel,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
    OpenThread,
} from '@/types/chat';

interface ChatShowProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    channel: ActiveChannel;
    messages: ChatMessage[];
    thread: OpenThread | null;
}

export default function ChatShow({
    workspace,
    channels,
    directMessages,
    channel,
    messages,
    thread,
}: ChatShowProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();
    const [searchOpen, setSearchOpen] = useState(false);

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

    const userMenu = (
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
    );

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

            {/*
                Keyed by channel so every bit of live state — socket
                subscription, presence roster, typing timers, optimistic drafts
                — is thrown away when the member opens another conversation.
            */}
            <Conversation
                key={channel.id}
                workspace={workspace}
                channel={channel}
                messages={messages}
                thread={thread}
                currentUser={{ id: auth.user.id, name: auth.user.name }}
                userMenu={userMenu}
            />

            <SearchDialog
                workspace={workspace}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />
        </div>
    );
}
