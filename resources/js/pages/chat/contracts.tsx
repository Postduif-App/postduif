import { Head, Link, router } from '@inertiajs/react';
import { FileSignature, Plus, Upload } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { show, store } from '@/routes/chat/contracts';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

interface ContractRow {
    id: string;
    title: string;
    status: string;
    statusLabel: string;
    authorName: string | null;
    createdAt: string | null;
    expiresAt: string | null;
    signerCount: number;
    signedCount: number;
    /** Read from the date rather than the column — see the controller. */
    hasExpired: boolean;
}

interface ContractsProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    archivedChannels: ArchivedChannel[];
    sections: ChannelSectionRow[];
    inboxUnread: number;
    scheduledBroadcasts: ScheduledBroadcast[];
    workspaces: WorkspaceOption[];
    contracts: ContractRow[];
    workspaceSlug: string;
}

/**
 * The colour a row's status is shown in.
 *
 * Three tones rather than five, because the useful distinction is not which of
 * the five states it is — the label says that — but whether this row still
 * wants something from the reader.
 */
function toneFor(row: ContractRow): string {
    if (row.status === 'completed') {
        return 'text-emerald-600';
    }

    if (
        row.status === 'cancelled' ||
        row.status === 'expired' ||
        row.hasExpired
    ) {
        return 'text-destructive';
    }

    return 'text-muted-foreground';
}

export default function Contracts({
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
    contracts,
    workspaceSlug,
}: ContractsProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [startOpen, setStartOpen] = useState(false);

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={t('contracts.list.title')} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={<UserMenu />}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <ChannelMenuButton />

                    <h1 className="text-sm font-semibold">
                        {t('contracts.list.title')}
                    </h1>

                    <Button
                        className="ml-auto"
                        size="sm"
                        onClick={() => setStartOpen(true)}
                    >
                        <Plus className="size-4" />
                        {t('contracts.list.new')}
                    </Button>
                </header>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto max-w-3xl px-4 py-6">
                        {contracts.length === 0 ? (
                            <div className="rounded-lg border border-dashed px-6 py-12 text-center">
                                <FileSignature className="mx-auto size-8 text-muted-foreground" />
                                <p className="mt-3 text-sm font-medium">
                                    {t('contracts.list.empty')}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {t('contracts.list.empty_hint')}
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {contracts.map((row) => (
                                    <li key={row.id}>
                                        <Link
                                            href={show.url({
                                                workspace: workspaceSlug,
                                                contract: row.id,
                                            })}
                                            className="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/50"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {row.title}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.authorName}
                                                    {row.createdAt !== null && (
                                                        <>
                                                            {' · '}
                                                            {formats.date.format(
                                                                new Date(
                                                                    row.createdAt,
                                                                ),
                                                            )}
                                                        </>
                                                    )}
                                                </p>
                                            </div>

                                            <div className="shrink-0 text-right">
                                                <p
                                                    className={cn(
                                                        'text-xs font-medium',
                                                        toneFor(row),
                                                    )}
                                                >
                                                    {row.statusLabel}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'contracts.detail.tally',
                                                        {
                                                            done: row.signedCount,
                                                            total: row.signerCount,
                                                        },
                                                    )}
                                                </p>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </main>

            <StartContractDialog
                open={startOpen}
                onOpenChange={setStartOpen}
                workspaceSlug={workspaceSlug}
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

/**
 * Where a contract begins: a title and a PDF.
 *
 * Nothing else is asked here. Who signs it and by when are decided on the
 * screen after, with the document in front of you — and asking for them now
 * would mean asking somebody to name signers for a page they have not looked at.
 */
function StartContractDialog({
    open,
    onOpenChange,
    workspaceSlug,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
}) {
    const { t } = useTranslate();

    const [title, setTitle] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = () => {
        if (file === null || title.trim() === '') {
            return;
        }

        setBusy(true);
        setError(null);

        router.post(
            store.url(workspaceSlug),
            { title, file } as unknown as Record<string, never>,
            {
                forceFormData: true,
                onError: (errors) => setError(errors.file ?? null),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('contracts.list.new')}</DialogTitle>
                    <DialogDescription>
                        {t('contracts.list.new_hint')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1">
                    <Label htmlFor="contract-title">
                        {t('contracts.list.field_title')}
                    </Label>
                    <Input
                        id="contract-title"
                        value={title}
                        maxLength={200}
                        onChange={(event) => setTitle(event.target.value)}
                    />
                </div>

                <div className="grid gap-1">
                    <Label htmlFor="contract-file">
                        {t('contracts.list.field_file')}
                    </Label>
                    <input
                        id="contract-file"
                        type="file"
                        accept="application/pdf"
                        onChange={(event) =>
                            setFile(event.target.files?.[0] ?? null)
                        }
                        className="text-sm"
                    />
                </div>

                {/*
                    The refusals from the upload are spelled out sentences — see
                    NormalisePdf — so they are shown as they came rather than
                    replaced with something shorter.
                */}
                {error !== null && (
                    <p
                        role="alert"
                        className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    >
                        {error}
                    </p>
                )}

                <div className="flex justify-end">
                    <Button
                        onClick={submit}
                        disabled={busy || file === null || title.trim() === ''}
                    >
                        <Upload className="size-4" />
                        {t('contracts.list.upload')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
