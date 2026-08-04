import { Head, router } from '@inertiajs/react';
import { Check, Clock, Hash, KeyRound, Lock, Plus } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { SendSecretDialog } from '@/components/chat/send-secret-dialog';
import { Button } from '@/components/ui/button';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy } from '@/routes/chat/sent-secrets';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/** One secret this member put aside, in whatever state it has reached. */
interface SecretRow {
    id: string;
    label: string;
    /** Null when nobody was named — the ordinary case for a standalone link. */
    recipientName: string | null;
    /** Null when it was never announced in a channel. */
    channelLabel: string | null;
    state: 'pending' | 'revealed' | 'expired';
    needsPassword: boolean;
    createdAt: string | null;
    expiresAt: string;
    revealedAt: string | null;
}

interface SecretsPageProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    archivedChannels: ArchivedChannel[];
    sections: ChannelSectionRow[];
    inboxUnread: number;
    /** Announcements this member has waiting, for the broadcast dialog. */
    scheduledBroadcasts: ScheduledBroadcast[];
    workspaces: WorkspaceOption[];
    /** Everything this member put aside, picked up and expired included. */
    secrets: SecretRow[];
    /** Everybody in the workspace, so a link can optionally be addressed. */
    people: { id: number; name: string }[];
}

/**
 * Everything this member has put aside, and the place to put aside one more.
 *
 * The list keeps the spent ones on purpose. That is the difference between this
 * and the channel card: a card answers "is er iets voor mij", while this page
 * answers "is dat wachtwoord van vorige week ooit opgehaald" — and a list that
 * had already swept those rows away would answer that with the same blank space
 * as "ik heb het nooit verstuurd".
 *
 * What it cannot show, on any row, is the secret. Not withheld — absent: the
 * ciphertext is shut to this application, and the key only ever existed in the
 * browser that made it.
 */
export default function Secrets({
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
    secrets,
    people,
}: SecretsPageProps) {
    useSessionGuard();

    const { t } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [sendOpen, setSendOpen] = useState(false);
    // Bumped on every open and used as the dialog's key, the same way the other
    // screens do it: a fresh mount is what clears the fields, so no half-typed
    // secret is ever left over from last time.
    const [sendKey, setSendKey] = useState(0);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title={t('account.sent_secrets.head')} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                secretsActive
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
                    <KeyRound className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('account.sent_secrets.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('account.sent_secrets.description')}
                        </p>
                    </div>

                    <Button
                        size="sm"
                        className="ml-auto"
                        onClick={() => {
                            setSendKey((key) => key + 1);
                            setSendOpen(true);
                        }}
                    >
                        <Plus className="size-4" />
                        {t('account.sent_secrets.new')}
                    </Button>
                </header>

                <div className="flex-1 overflow-y-auto">
                    {secrets.length === 0 ? (
                        <div className="p-8 text-center">
                            <p className="text-sm text-muted-foreground">
                                {t('account.sent_secrets.empty')}
                            </p>
                            <p className="mx-auto mt-2 max-w-md text-xs text-muted-foreground">
                                {t('account.sent_secrets.empty_hint')}
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y">
                            {secrets.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <StateIcon state={row.state} />

                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'flex items-center gap-2 text-sm font-medium',
                                                row.state !== 'pending' &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            <span className="truncate">
                                                {row.label}
                                            </span>
                                            {row.needsPassword && (
                                                <Lock
                                                    className="size-3 shrink-0 text-muted-foreground"
                                                    aria-label={t(
                                                        'account.sent_secrets.has_password',
                                                    )}
                                                />
                                            )}
                                        </span>

                                        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <span>
                                                {row.recipientName === null
                                                    ? t(
                                                          'account.sent_secrets.for_nobody',
                                                      )
                                                    : t(
                                                          'account.sent_secrets.for_person',
                                                          {
                                                              name: row.recipientName,
                                                          },
                                                      )}
                                            </span>
                                            {row.channelLabel !== null && (
                                                <span className="flex items-center gap-0.5">
                                                    <Hash className="size-3" />
                                                    {row.channelLabel}
                                                </span>
                                            )}
                                            <span>
                                                {describe(row, t, formats)}
                                            </span>
                                        </span>
                                    </span>

                                    {/*
                                        Only while there is something left to
                                        take back. A spent secret has no button:
                                        there is nothing to withdraw, and one
                                        that did nothing would read as broken.
                                    */}
                                    {row.state === 'pending' && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        t(
                                                            'account.sent_secrets.revoke_question',
                                                        ),
                                                    )
                                                ) {
                                                    router.delete(
                                                        destroy.url({
                                                            workspace:
                                                                workspace.slug,
                                                            sentSecret: row.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                            className="shrink-0 rounded px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {t('account.sent_secrets.revoke')}
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>

            {/*
                No channel, so nothing is posted anywhere — see SendSecretDialog,
                which switches endpoints on exactly this.
            */}
            <SendSecretDialog
                key={sendKey}
                workspaceSlug={workspace.slug}
                channelId={null}
                people={people}
                open={sendOpen}
                onOpenChange={setSendOpen}
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
                    onSendSecret: () => {
                        setSendKey((key) => key + 1);
                        setSendOpen(true);
                    },
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

function StateIcon({ state }: { state: SecretRow['state'] }) {
    if (state === 'revealed') {
        return <Check className="size-4 shrink-0 text-muted-foreground" />;
    }

    if (state === 'expired') {
        return <Clock className="size-4 shrink-0 text-muted-foreground/60" />;
    }

    return <KeyRound className="size-4 shrink-0 text-primary" />;
}

/**
 * The one sentence a row gets about where it stands.
 *
 * "Opgehaald" wins over the expiry date even when both are true: once somebody
 * has read it the expiry stopped mattering, and showing a date beside it would
 * invite the reading that it might still be out there.
 *
 * Takes t and the formatters rather than reaching for them itself: a function
 * outside a component cannot call a hook, and the words and the date shape both
 * belong to the reader's language.
 */
function describe(
    row: SecretRow,
    t: ReturnType<typeof useTranslate>['t'],
    formats: ReturnType<typeof useFormats>,
): string {
    if (row.state === 'revealed' && row.revealedAt !== null) {
        return t('account.sent_secrets.revealed_at', {
            moment: formats.moment.format(new Date(row.revealedAt)),
        });
    }

    if (row.state === 'expired') {
        return t('account.sent_secrets.expired_at', {
            moment: formats.moment.format(new Date(row.expiresAt)),
        });
    }

    return t('account.sent_secrets.expires_at', {
        moment: formats.moment.format(new Date(row.expiresAt)),
    });
}
