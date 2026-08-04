import { Head } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import type { TransferManagerProps } from '@/components/transfers/transfer-manager';
import { TransferManager } from '@/components/transfers/transfer-manager';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

interface TransfersPageProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    /** Channels that were put away, for whoever may take them back out. */
    archivedChannels: ArchivedChannel[];
    /** The groups this member arranged for themselves. */
    sections: ChannelSectionRow[];
    /** Unread inbox rows of every kind, counted by the server. */
    inboxUnread: number;
    /** Announcements this member has waiting, for the broadcast dialog. */
    scheduledBroadcasts: ScheduledBroadcast[];
    /** Every workspace this member belongs to, for the switcher up top. */
    workspaces: WorkspaceOption[];
    /** Whether this member may put a transfer out at all. */
    canSend: boolean;
    maxTransferKb: number;
    maxTransferDays: number;
    audienceOptions: TransferManagerProps['audienceOptions'];
    /** True for a beheerder, who sees the whole workspace's rather than theirs. */
    seesEveryone: boolean;
    transfers: TransferManagerProps['transfers'];
}

/**
 * Sending files by link, inside the app rather than under settings.
 *
 * The same shell every other cross-channel screen uses — the ticket list, the
 * inbox — because it needs the same sidebar, the same unread counts and the
 * same live connection. What it draws is TransferManager, which was this screen
 * back when it lived in workspace settings and did not have to change to move.
 */
export default function WorkspaceTransfers({
    workspace,
    channels,
    directMessages,
    activeThreads,
    workspaceTags,
    archivedChannels,
    sections,
    inboxUnread,
    scheduledBroadcasts,
    workspaces,
    canSend,
    maxTransferKb,
    maxTransferDays,
    audienceOptions,
    seesEveryone,
    transfers,
}: TransfersPageProps) {
    const { t } = useTranslate();

    useSessionGuard();

    const [searchOpen, setSearchOpen] = useState(false);

    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title="Bestanden versturen" />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                transfersActive
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
                    <Send className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('screens.transfers.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {seesEveryone
                                ? `Alles wat vanuit ${workspace.name} klaarstaat achter een downloadlink`
                                : `Wat jij vanuit ${workspace.name} hebt klaargezet`}
                        </p>
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto p-4">
                    <div className="mx-auto max-w-3xl">
                        <TransferManager
                            workspaceName={workspace.name}
                            workspaceSlug={workspace.slug}
                            canSend={canSend}
                            maxTransferKb={maxTransferKb}
                            maxTransferDays={maxTransferDays}
                            audienceOptions={audienceOptions}
                            seesEveryone={seesEveryone}
                            transfers={transfers}
                        />
                    </div>
                </div>
            </main>

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
            <NewDirectMessageDialog
                workspace={workspace}
                open={directOpen}
                onOpenChange={setDirectOpen}
            />
            <InvitePeopleDialog
                workspace={workspace}
                channels={channels}
                open={inviteOpen}
                onOpenChange={setInviteOpen}
            />
            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                scheduledBroadcasts={scheduledBroadcasts}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />
        </div>
    );
}
