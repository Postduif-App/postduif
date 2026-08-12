import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    BellRing,
    Check,
    Clock,
    Copy,
    Download,
    Eye,
    FileText,
    Pencil,
    Plus,
    RefreshCw,
    Send,
    Share2,
    X,
} from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    cancel,
    edit,
    post as postToChannel,
    remind,
    retry,
    send as sendContract,
    show,
} from '@/routes/chat/contracts';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

type SignerState = 'signed' | 'declined' | 'opened' | 'waiting';

interface SignerRow {
    name: string;
    email: string;
    openedAt: string | null;
    signedAt: string | null;
    declinedAt: string | null;
    declineReason: string | null;
    remindedAt: string | null;
    state: SignerState;
}

interface ContractDetail {
    id: string;
    title: string;
    message: string | null;
    status: string;
    statusLabel: string;
    statusDescription: string;
    pageCount: number;
    authorName: string | null;
    createdAt: string | null;
    expiresAt: string | null;
    completedAt: string | null;
    signedCount: number;
    signerCount: number;
    /** Whether the finished document is ready, coming, or went wrong. */
    signedCopyState: 'ready' | 'failed' | 'pending' | 'none';
    sourceUrl: string;
    downloadUrl: string | null;
    signers: SignerRow[];
}

interface ContractShowProps {
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
    contract: ContractDetail;
    can: {
        remind: boolean;
        cancel: boolean;
        update: boolean;
        /** A draft still waiting for its signers, rather than a right of its own. */
        send: boolean;
    };
    members: { id: number; name: string; email: string }[];
    workspaceSlug: string;
}

/** One person being named as a signer, while the list is being written. */
interface SignerDraft {
    name: string;
    email: string;
    /** Set when they were picked from the workspace rather than typed in. */
    userId: number | null;
}

/**
 * The icon and the tone for each of the four things a signer can be.
 *
 * Waiting and opened are kept apart, which is the distinction this screen
 * exists for: "hij heeft het niet eens geopend" and "hij heeft het gezien en
 * niets gedaan" lead to different next steps for the person waiting.
 */
const STATES: Record<
    SignerState,
    { icon: typeof Check; tone: string; label: TranslationKey }
> = {
    signed: {
        icon: Check,
        tone: 'text-emerald-600',
        label: 'contracts.detail.signed',
    },
    declined: {
        icon: Ban,
        tone: 'text-destructive',
        label: 'contracts.detail.declined',
    },
    opened: {
        icon: Eye,
        tone: 'text-muted-foreground',
        label: 'contracts.detail.opened',
    },
    waiting: {
        icon: Clock,
        tone: 'text-muted-foreground',
        label: 'contracts.detail.waiting',
    },
};

export default function ContractShow({
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
    contract,
    can,
    members,
    workspaceSlug,
}: ContractShowProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const moment = (value: string | null) =>
        value === null ? null : formats.dateTime.format(new Date(value));

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={contract.title} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                contractsActive
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
                    <Link
                        href={`/app/${workspaceSlug}`}
                        aria-label={t('contracts.editor.back')}
                        className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {contract.title}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {contract.statusLabel}
                            {' · '}
                            {t('contracts.detail.tally', {
                                done: contract.signedCount,
                                total: contract.signerCount,
                            })}
                        </p>
                    </div>

                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        {can.update && (
                            <Link
                                href={edit.url({
                                    workspace: workspaceSlug,
                                    contract: contract.id,
                                })}
                                className={cn(
                                    buttonVariants({
                                        variant: 'ghost',
                                        size: 'sm',
                                    }),
                                )}
                            >
                                <Pencil className="size-4" />
                                {t('contracts.detail.edit')}
                            </Link>
                        )}

                        {can.remind && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        remind.url({
                                            workspace: workspaceSlug,
                                            contract: contract.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <BellRing className="size-4" />
                                {t('contracts.detail.remind')}
                            </Button>
                        )}

                        {/*
                            Withdrawing stays available on a half-signed
                            contract, where editing does not: stopping something
                            is not the same as changing it, and that is exactly
                            the contract somebody most urgently needs to stop.
                        */}
                        {can.cancel && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() =>
                                    router.post(
                                        cancel.url({
                                            workspace: workspaceSlug,
                                            contract: contract.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <X className="size-4" />
                                {t('contracts.detail.cancel')}
                            </Button>
                        )}
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto max-w-3xl space-y-8 px-4 py-6">
                        <section className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                {contract.statusDescription}
                            </p>

                            {contract.message && (
                                <blockquote className="border-l-2 pl-4 text-sm text-muted-foreground">
                                    {contract.message}
                                </blockquote>
                            )}

                            <dl className="grid gap-x-6 gap-y-1 pt-2 text-sm sm:grid-cols-2">
                                <Fact
                                    label={t('contracts.detail.sent_by')}
                                    value={contract.authorName}
                                />
                                <Fact
                                    label={t('contracts.detail.pages')}
                                    value={tChoice(
                                        'contracts.editor.page_count',
                                        contract.pageCount,
                                    )}
                                />
                                <Fact
                                    label={t('contracts.detail.expires_at')}
                                    value={
                                        moment(contract.expiresAt) ??
                                        t('contracts.detail.no_deadline')
                                    }
                                />
                                <Fact
                                    label={t('contracts.detail.completed_at')}
                                    value={moment(contract.completedAt)}
                                />
                            </dl>
                        </section>

                        {can.send && (
                            <SendPanel
                                contractId={contract.id}
                                members={members}
                                channels={channels.filter(
                                    (row) => row.type !== 'dm',
                                )}
                                workspaceSlug={workspaceSlug}
                            />
                        )}

                        <section className="space-y-1">
                            <h2 className="text-xs font-semibold text-muted-foreground uppercase">
                                {t('contracts.detail.people')}
                            </h2>

                            <ul className="divide-y rounded-lg border">
                                {contract.signers.map((signer) => (
                                    <SignerLine
                                        key={signer.email}
                                        signer={signer}
                                        moment={moment}
                                    />
                                ))}

                                {contract.signers.length === 0 && (
                                    <li className="px-4 py-6 text-sm text-muted-foreground">
                                        {t('contracts.detail.nobody')}
                                    </li>
                                )}
                            </ul>
                        </section>

                        <section className="flex flex-wrap items-center gap-2 border-t pt-4">
                            <a
                                href={contract.sourceUrl}
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    }),
                                )}
                            >
                                <FileText className="size-4" />
                                {t('contracts.detail.document')}
                            </a>

                            {contract.downloadUrl !== null && (
                                <a
                                    href={contract.downloadUrl}
                                    className={cn(
                                        buttonVariants({ size: 'sm' }),
                                    )}
                                >
                                    <Download className="size-4" />
                                    {t('contracts.detail.signed_copy')}
                                </a>
                            )}

                            {/*
                                The two states that are not a link, and they are
                                worth saying out loud rather than leaving a
                                missing button to explain itself: one means wait,
                                the other means the signatures are safe but the
                                document could not be composed.
                            */}
                            {contract.signedCopyState === 'pending' && (
                                <span className="text-xs text-muted-foreground">
                                    {t('contracts.detail.copy_pending')}
                                </span>
                            )}

                            {contract.signedCopyState === 'failed' && (
                                <>
                                    <span className="text-xs text-destructive">
                                        {t('contracts.detail.copy_failed')}
                                    </span>
                                    {/*
                                        Worth offering, because the ways this
                                        fails are mostly temporary — and the
                                        alternative is a contract that is
                                        properly signed and permanently without
                                        its document.
                                    */}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                retry.url({
                                                    workspace: workspaceSlug,
                                                    contract: contract.id,
                                                }),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <RefreshCw className="size-4" />
                                        {t('contracts.detail.retry')}
                                    </Button>
                                </>
                            )}
                        </section>

                        <ShareRow
                            contractId={contract.id}
                            channels={channels.filter(
                                (row) => row.type !== 'dm',
                            )}
                            workspaceSlug={workspaceSlug}
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
 * Naming the people and putting it in the post.
 *
 * Only ever shown for a draft, because sending is the step that hands the
 * document to the outside world and there is no second chance at it: the way to
 * change a contract that is already out is to withdraw it and start again,
 * which is deliberately more work and also more honest.
 */
function SendPanel({
    contractId,
    members,
    channels,
    workspaceSlug,
}: {
    contractId: string;
    members: { id: number; name: string; email: string }[];
    channels: ChannelSummary[];
    workspaceSlug: string;
}) {
    const { t } = useTranslate();

    const [signers, setSigners] = useState<SignerDraft[]>([
        { name: '', email: '', userId: null },
    ]);
    const [validForDays, setValidForDays] = useState('14');
    const [notifyChannelId, setNotifyChannelId] = useState('');
    const [busy, setBusy] = useState(false);

    const change = (at: number, patch: Partial<SignerDraft>) =>
        setSigners((current) =>
            current.map((row, index) =>
                index === at ? { ...row, ...patch } : row,
            ),
        );

    const ready = signers.every(
        (row) => row.name.trim() !== '' && row.email.trim() !== '',
    );

    const submit = () => {
        setBusy(true);

        router.post(
            sendContract.url({
                workspace: workspaceSlug,
                contract: contractId,
            }),
            {
                signers: signers.map((row) => ({
                    name: row.name,
                    email: row.email,
                    user_id: row.userId,
                })),
                valid_for_days:
                    validForDays === '' ? null : Number(validForDays),
                notify_channel_id:
                    notifyChannelId === '' ? null : Number(notifyChannelId),
            } as unknown as Record<string, never>,
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <h2 className="text-xs font-semibold text-muted-foreground uppercase">
                {t('contracts.send.title')}
            </h2>

            {signers.map((row, index) => (
                <div key={index} className="flex items-end gap-2">
                    <div className="grid flex-1 gap-1">
                        <Label
                            htmlFor={`signer-name-${index}`}
                            className="text-xs"
                        >
                            {t('contracts.send.name')}
                        </Label>
                        <Input
                            id={`signer-name-${index}`}
                            value={row.name}
                            maxLength={120}
                            onChange={(event) =>
                                change(index, { name: event.target.value })
                            }
                        />
                    </div>

                    <div className="grid flex-1 gap-1">
                        <Label
                            htmlFor={`signer-email-${index}`}
                            className="text-xs"
                        >
                            {t('contracts.send.email')}
                        </Label>
                        <Input
                            id={`signer-email-${index}`}
                            type="email"
                            value={row.email}
                            onChange={(event) =>
                                change(index, {
                                    email: event.target.value,
                                    /*
                                     * Typing over an address picked from the
                                     * list makes it an outsider again. The
                                     * user_id is what makes the DM possible,
                                     * and it must not survive the address it
                                     * was picked for.
                                     */
                                    userId: null,
                                })
                            }
                        />
                    </div>

                    {signers.length > 1 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={t('contracts.send.remove')}
                            onClick={() =>
                                setSigners((current) =>
                                    current.filter((_, at) => at !== index),
                                )
                            }
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            ))}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        setSigners((current) => [
                            ...current,
                            { name: '', email: '', userId: null },
                        ])
                    }
                >
                    <Plus className="size-4" />
                    {t('contracts.send.add')}
                </Button>

                {members.length > 0 && (
                    <select
                        value=""
                        aria-label={t('contracts.send.pick_member')}
                        onChange={(event) => {
                            const member = members.find(
                                (one) => String(one.id) === event.target.value,
                            );

                            if (member !== undefined) {
                                setSigners((current) => [
                                    ...current.filter(
                                        (row) => row.email.trim() !== '',
                                    ),
                                    {
                                        name: member.name,
                                        email: member.email,
                                        userId: member.id,
                                    },
                                ]);
                            }
                        }}
                        className="rounded-md border bg-background px-2 py-1.5 text-sm"
                    >
                        <option value="">
                            {t('contracts.send.pick_member')}
                        </option>
                        {members.map((member) => (
                            <option key={member.id} value={member.id}>
                                {member.name}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1">
                    <Label htmlFor="valid-days" className="text-xs">
                        {t('contracts.send.valid_days')}
                    </Label>
                    <Input
                        id="valid-days"
                        type="number"
                        min={1}
                        max={365}
                        value={validForDays}
                        onChange={(event) =>
                            setValidForDays(event.target.value)
                        }
                    />
                </div>

                <div className="grid gap-1">
                    <Label htmlFor="notify-channel" className="text-xs">
                        {t('contracts.send.notify_channel')}
                    </Label>
                    <select
                        id="notify-channel"
                        value={notifyChannelId}
                        onChange={(event) =>
                            setNotifyChannelId(event.target.value)
                        }
                        className="rounded-md border bg-background px-3 py-2 text-sm"
                    >
                        {/*
                            No channel is a real answer, not a missing one: the
                            author still gets their mail. What the choice
                            decides is which colleagues learn that a particular
                            person signed a particular document.
                        */}
                        <option value="">
                            {t('contracts.send.no_channel')}
                        </option>
                        {channels.map((channel) => (
                            <option key={channel.id} value={channel.id}>
                                #{channel.name ?? channel.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            <div className="flex justify-end">
                <Button onClick={submit} disabled={busy || !ready}>
                    <Send className="size-4" />
                    {t('contracts.send.submit')}
                </Button>
            </div>
        </section>
    );
}

/**
 * The two ways to hand this contract's address to colleagues.
 *
 * Neither invites anybody to sign — the signers hold links of their own. This
 * is the colleagues' view: here is what we sent, and here is how far it has got.
 */
function ShareRow({
    contractId,
    channels,
    workspaceSlug,
}: {
    contractId: string;
    channels: ChannelSummary[];
    workspaceSlug: string;
}) {
    const { t } = useTranslate();
    const [copied, copy] = useClipboard();
    const [channel, setChannel] = useState(
        channels[0] === undefined ? '' : String(channels[0].id),
    );

    const url = `${window.location.origin}${show.url({
        workspace: workspaceSlug,
        contract: contractId,
    })}`;

    return (
        <section className="flex flex-wrap items-center gap-2 border-t pt-4">
            <Button variant="outline" size="sm" onClick={() => void copy(url)}>
                {copied === url ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
                {t('contracts.detail.copy_link')}
            </Button>

            {channels.length > 0 && (
                <>
                    <select
                        value={channel}
                        aria-label={t('contracts.detail.post_channel')}
                        onChange={(event) => setChannel(event.target.value)}
                        className="rounded-md border bg-background px-2 py-1.5 text-sm"
                    >
                        {channels.map((one) => (
                            <option key={one.id} value={one.id}>
                                #{one.name ?? one.label}
                            </option>
                        ))}
                    </select>

                    <Button
                        variant="outline"
                        size="sm"
                        disabled={channel === ''}
                        onClick={() =>
                            router.post(
                                postToChannel.url({
                                    workspace: workspaceSlug,
                                    contract: contractId,
                                }),
                                { channel_id: Number(channel) },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Share2 className="size-4" />
                        {t('contracts.detail.post')}
                    </Button>
                </>
            )}
        </section>
    );
}

/** One label-and-value pair, left out entirely when there is no value. */
function Fact({ label, value }: { label: string; value: string | null }) {
    if (value === null) {
        return null;
    }

    return (
        <div className="flex gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 truncate">{value}</dd>
        </div>
    );
}

/**
 * One person and what they have done.
 *
 * The moment is shown beside the state rather than instead of it, because both
 * are read: the state answers "waar staan we" at a glance and the moment
 * answers "hoe lang al", which is what decides whether to reach for the
 * reminder button.
 */
function SignerLine({
    signer,
    moment,
}: {
    signer: SignerRow;
    moment: (value: string | null) => string | null;
}) {
    const { t } = useTranslate();

    const state = STATES[signer.state];
    const Icon = state.icon;

    const when =
        moment(signer.signedAt) ??
        moment(signer.declinedAt) ??
        moment(signer.openedAt);

    return (
        <li className="flex items-start gap-3 px-4 py-3">
            <Icon className={cn('mt-0.5 size-4 shrink-0', state.tone)} />

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{signer.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {signer.email}
                </p>

                {signer.declineReason !== null && (
                    <p className="mt-1 text-xs text-muted-foreground italic">
                        {`“${signer.declineReason}”`}
                    </p>
                )}
            </div>

            <div className="shrink-0 text-right">
                <p className={cn('text-xs font-medium', state.tone)}>
                    {t(state.label)}
                </p>
                {when !== null && (
                    <p className="text-xs text-muted-foreground">{when}</p>
                )}
                {signer.state === 'waiting' && signer.remindedAt !== null && (
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.detail.reminded', {
                            date: moment(signer.remindedAt) ?? '',
                        })}
                    </p>
                )}
            </div>
        </li>
    );
}
