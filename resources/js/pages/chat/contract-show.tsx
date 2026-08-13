import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    BellRing,
    Check,
    Clock,
    Copy,
    CopyPlus,
    Download,
    Eye,
    FileText,
    Mail,
    Pencil,
    Plus,
    RefreshCw,
    Send,
    Share2,
    Trash2,
    Users,
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
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useClipboard } from '@/hooks/use-clipboard';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    cancel,
    destroy as destroyContract,
    duplicate as duplicateContract,
    edit,
    index as contractsIndex,
    post as postToChannel,
    remind,
    retry,
    copy as sendSignedCopy,
    send as sendContract,
    show,
    signers as signersOf,
    template as templateRoutes,
} from '@/routes/chat/contracts';
/*
 * The nested one is imported on its own. The bundle above re-exports the
 * template route as a bare function, and the sign-along address hangs off the
 * generated sub-module rather than off that.
 */
import { signAlong as signAlongRoute } from '@/routes/chat/contracts/template';
import type { Auth } from '@/types/auth';
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
    /** Set when they were picked from the workspace rather than typed in. */
    userId: number | null;
    openedAt: string | null;
    signedAt: string | null;
    declinedAt: string | null;
    declineReason: string | null;
    remindedAt: string | null;
    /** When this person was posted the finished document. */
    copySentAt: string | null;
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
    /** The finished document as a file to keep. */
    downloadUrl: string | null;
    /** The same document, to read in a tab rather than to file away. */
    signedCopyUrl: string | null;
    /** Where the viewer signs, when they are on the list themselves. */
    mySignUrl: string | null;
    signers: SignerRow[];
}

/**
 * Everything that is true of a mould and of nothing else, or null when this row
 * is an ordinary contract.
 *
 * The blockers arrive as a list of reasons rather than as a boolean with the
 * reasons worked out here. Whether a template may be used is the server's
 * question — it is the thing that has to refuse an API call — and a screen that
 * re-derived the answer would be a second opinion waiting to disagree with the
 * first.
 */
interface TemplateDetail {
    /** How many people it will be sent to. Null while nobody has said. */
    requiredSigners: number | null;
    /** Those recipients plus the author, when they sign along. */
    partyCount: number;
    signsAlong: boolean;
    authorSigned: boolean;
    /** The author's own signing page, while they still have to sign. */
    signUrl: string | null;
    isReadyToSend: boolean;
    blockers: ('document' | 'recipients' | 'fields' | 'signature')[];
    maxRecipients: number;
    /** The fewest the boxes already drawn will fit in. */
    minRecipients: number;
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
    /** Set only when this row is kept to be sent again rather than sent. */
    template: TemplateDetail | null;
    can: {
        remind: boolean;
        cancel: boolean;
        update: boolean;
        /** Throwing it away for good, which a finished contract never allows. */
        delete: boolean;
        /** A draft still waiting for its signers, rather than a right of its own. */
        send: boolean;
        /**
         * Posting the finished document round again. False until there is one
         * and somebody actually signed it.
         */
        sendCopy: boolean;
        /**
         * Making a fresh draft of this same document for other people. True
         * whatever the status is — it is the one thing left to do with a
         * contract that has been signed.
         */
        duplicate: boolean;
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
    template,
    can,
    members,
    workspaceSlug,
}: ContractShowProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [duplicateOpen, setDuplicateOpen] = useState(false);

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
                        href={contractsIndex.url(workspaceSlug)}
                        aria-label={t('contracts.editor.back')}
                        className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {contract.title}
                        </h1>
                        {/*
                            A template has no tally to give. "0 van de 1
                            getekend" would be counting the one row it may have
                            against a roster that does not exist, so it says what
                            it is and how many parties it is drawn for instead.
                        */}
                        <p className="truncate text-xs text-muted-foreground">
                            {template === null ? (
                                <>
                                    {contract.statusLabel}
                                    {' · '}
                                    {t('contracts.detail.tally', {
                                        done: contract.signedCount,
                                        total: contract.signerCount,
                                    })}
                                </>
                            ) : (
                                <>
                                    {t('contracts.template.title')}
                                    {' · '}
                                    {tChoice(
                                        'contracts.template.parties',
                                        template.partyCount,
                                    )}
                                </>
                            )}
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
                            The way to reuse a contract that can no longer be
                            changed, which after the first signature is every
                            contract. It leaves this one alone and starts a new
                            draft — so it sits with the harmless buttons, on
                            this side of the two destructive ones.
                        */}
                        {can.duplicate && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setDuplicateOpen(true)}
                            >
                                <CopyPlus className="size-4" />
                                {t('contracts.detail.duplicate')}
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

                        {/*
                            Beside withdrawing rather than instead of it, and
                            behind a confirmation the withdraw button does not
                            have: intrekken is a step in a contract's life and
                            can be explained to whoever holds a link, while this
                            takes the document off the disk and leaves that
                            person with nothing to read at all.
                        */}
                        {can.delete && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeleteOpen(true)}
                            >
                                <Trash2 className="size-4" />
                                {t('contracts.detail.delete')}
                            </Button>
                        )}
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto max-w-3xl space-y-8 px-4 py-6">
                        <section className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                {template === null
                                    ? contract.statusDescription
                                    : t('contracts.template.lead')}
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
                                {/*
                                    A template never runs out and is never
                                    finished, so both of these would be a row
                                    saying "nooit" about something that cannot
                                    happen — see CreateContract, which refuses to
                                    put a deadline on one.
                                */}
                                {template === null && (
                                    <>
                                        <Fact
                                            label={t(
                                                'contracts.detail.expires_at',
                                            )}
                                            value={
                                                moment(contract.expiresAt) ??
                                                t(
                                                    'contracts.detail.no_deadline',
                                                )
                                            }
                                        />
                                        <Fact
                                            label={t(
                                                'contracts.detail.completed_at',
                                            )}
                                            value={moment(contract.completedAt)}
                                        />
                                    </>
                                )}
                            </dl>
                        </section>

                        {/*
                            The author who put themselves on the list. They are
                            not redirected here the way an ordinary signer is —
                            that would take the screen that can remind and
                            withdraw away from the person most likely to need it
                            — so the way to their own page is a link.
                        */}
                        {contract.mySignUrl !== null && template === null && (
                            <a
                                href={contract.mySignUrl}
                                className={cn(
                                    buttonVariants({ size: 'sm' }),
                                    'w-fit',
                                )}
                            >
                                <Pencil className="size-4" />
                                {t('contracts.detail.sign_yourself')}
                            </a>
                        )}

                        {/*
                            The panel that stands in for the send panel. A
                            template is never sent, so what it asks for instead
                            is the two things a roster would otherwise have
                            settled: how many people, and whether the author is
                            one of them.
                        */}
                        {template !== null && (
                            <TemplatePanel
                                contractId={contract.id}
                                template={template}
                                canEdit={can.update}
                                workspaceSlug={workspaceSlug}
                            />
                        )}

                        {can.send && (
                            <SendPanel
                                contractId={contract.id}
                                saved={contract.signers}
                                members={members}
                                channels={channels.filter(
                                    (row) => row.type !== 'dm',
                                )}
                                workspaceSlug={workspaceSlug}
                            />
                        )}

                        {/*
                            Left out entirely on a template with nobody on it.
                            "Er zijn nog geen ondertekenaars uitgenodigd" is a
                            true sentence about a contract that is waiting to go
                            out and a misleading one about a mould, which is
                            never going to invite anybody: the panel above is
                            where its parties are decided.
                        */}
                        {(template === null || contract.signers.length > 0) && (
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
                        )}

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

                            {/*
                                Reading it and keeping it are two errands, and
                                somebody who only wants to check what the
                                finished document says should not end up with a
                                file in their downloads folder to prove it. One
                                route and one policy behind both — see
                                ContractController::download.
                            */}
                            {contract.signedCopyUrl !== null && (
                                <a
                                    href={contract.signedCopyUrl}
                                    className={cn(
                                        buttonVariants({ size: 'sm' }),
                                    )}
                                >
                                    <Eye className="size-4" />
                                    {t('contracts.detail.view_signed')}
                                </a>
                            )}

                            {contract.downloadUrl !== null && (
                                <a
                                    href={contract.downloadUrl}
                                    className={cn(
                                        buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        }),
                                    )}
                                >
                                    <Download className="size-4" />
                                    {t('contracts.detail.signed_copy')}
                                </a>
                            )}

                            {/*
                                Sending it round again, by hand.

                                It has already gone out by itself the moment the
                                copy was composed — see
                                RenderSignedContractJob — so this button is not
                                the way people normally get their document. It is
                                the answer to "ik heb hem nooit ontvangen", which
                                is a thing that happens to mail, and the useful
                                answer to that is to send it rather than to look
                                up whether it was sent.
                            */}
                            {can.sendCopy && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    title={t('contracts.detail.send_copy_hint')}
                                    onClick={() =>
                                        router.post(
                                            sendSignedCopy.url({
                                                workspace: workspaceSlug,
                                                contract: contract.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Mail className="size-4" />
                                    {t('contracts.detail.send_copy')}
                                </Button>
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

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>{contract.title}</AlertDialogTitle>
                        {/*
                            Two sentences, chosen by what is actually being
                            thrown away. A finished contract is the only thing
                            here somebody outside is relying on, and the right
                            to delete one is handed out on purpose — so the
                            person who has it is told exactly what goes, rather
                            than reading the same line that covers a draft
                            nobody ever sent.
                        */}
                        <AlertDialogDescription>
                            {t(
                                contract.status === 'completed'
                                    ? 'contracts.detail.delete_confirm_signed'
                                    : 'contracts.detail.delete_confirm',
                            )}
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
                            onClick={() =>
                                router.delete(
                                    destroyContract.url({
                                        workspace: workspaceSlug,
                                        contract: contract.id,
                                    }),
                                )
                            }
                        >
                            {t('contracts.detail.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <DuplicateDialog
                open={duplicateOpen}
                onOpenChange={setDuplicateOpen}
                contractId={contract.id}
                title={contract.title}
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
 * What a template has instead of a send panel.
 *
 * The two questions a roster would otherwise have answered, and they are asked
 * here because a template cannot answer them the ordinary way: the people it
 * will go to do not exist yet, so all it can say is how many of them there will
 * be, and whether the author stands at the head of the queue.
 *
 * The reasons it is not ready are spelled out rather than left to a greyed-out
 * button. A template that cannot be used is the most confusing thing on this
 * screen — nothing about the document looks wrong — and the four things that can
 * be missing are all in different places: the PDF, the number here, the boxes in
 * the editor, and a signature on a page of its own.
 *
 * Two saves rather than one form, because the two are governed differently. The
 * number is an ordinary edit and stops being allowed the moment the author signs;
 * the switch has to keep working after that, since taking your own signature back
 * off is the only way to a template you can edit again.
 */
function TemplatePanel({
    contractId,
    template,
    /** False once the author has signed — see ContractPolicy::update. */
    canEdit,
    workspaceSlug,
}: {
    contractId: string;
    template: TemplateDetail;
    canEdit: boolean;
    workspaceSlug: string;
}) {
    const { t, tChoice } = useTranslate();

    /*
     * Held as the string the box contains rather than as a number. Somebody
     * clearing the field to type "12" passes through "" on the way, and a state
     * that insisted on a number would put a 0 or a 1 back under their cursor.
     */
    const [recipients, setRecipients] = useState(
        template.requiredSigners === null
            ? ''
            : String(template.requiredSigners),
    );
    const [busy, setBusy] = useState(false);

    const wanted = Number(recipients);

    const valid =
        recipients !== '' &&
        Number.isInteger(wanted) &&
        wanted >= template.minRecipients &&
        wanted <= template.maxRecipients;

    const saveRecipients = () => {
        setBusy(true);

        router.put(
            templateRoutes.url({
                workspace: workspaceSlug,
                contract: contractId,
            }),
            { required_signers: wanted },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const toggleSigningAlong = (wantedAlong: boolean) => {
        setBusy(true);

        router.put(
            signAlongRoute.url({
                workspace: workspaceSlug,
                contract: contractId,
            }),
            { signs_along: wantedAlong },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="mr-auto text-xs font-semibold text-muted-foreground uppercase">
                    {t('contracts.template.title')}
                </h2>

                <span
                    className={cn(
                        'text-xs font-medium',
                        template.isReadyToSend
                            ? 'text-emerald-600'
                            : 'text-muted-foreground',
                    )}
                >
                    {t(
                        template.isReadyToSend
                            ? 'contracts.template.ready'
                            : 'contracts.template.not_ready',
                    )}
                </span>
            </div>

            {/*
                Every reason at once rather than the first one. Somebody who has
                to upload a document, set a number and draw the boxes should be
                able to see all three from here instead of discovering them one
                refusal at a time.
            */}
            {template.blockers.length > 0 && (
                <div className="space-y-1 rounded-md border border-dashed px-3 py-2">
                    <p className="text-xs font-medium">
                        {t('contracts.template.missing')}
                    </p>
                    <ul className="list-inside list-disc text-xs text-muted-foreground">
                        {template.blockers.map((reason) => (
                            <li key={reason}>
                                {t(`contracts.template.blockers.${reason}`)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-1 sm:max-w-xs">
                <Label htmlFor="template-recipients" className="text-xs">
                    {t('contracts.template.recipients')}
                </Label>

                <div className="flex items-center gap-2">
                    <Input
                        id="template-recipients"
                        type="number"
                        min={template.minRecipients}
                        max={template.maxRecipients}
                        value={recipients}
                        disabled={!canEdit}
                        onChange={(event) => setRecipients(event.target.value)}
                    />

                    <Button
                        variant="outline"
                        size="sm"
                        className="shrink-0"
                        disabled={busy || !valid || !canEdit}
                        onClick={saveRecipients}
                    >
                        <Users className="size-4" />
                        {t('contracts.template.recipients_save')}
                    </Button>
                </div>

                <p className="text-xs text-muted-foreground">
                    {t('contracts.template.recipients_hint')}
                </p>

                {/*
                    Only worth saying once it is actually a limit. On a template
                    whose boxes all belong to the first party the floor is one,
                    and announcing that would be explaining a rule nobody can
                    break.
                */}
                {template.minRecipients > 1 && (
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.template.recipients_floor', {
                            count: template.minRecipients,
                        })}
                    </p>
                )}
            </div>

            <div className="space-y-2 border-t pt-3">
                <label className="flex items-start gap-2 text-sm">
                    <Checkbox
                        checked={template.signsAlong}
                        disabled={busy}
                        onCheckedChange={(checked) =>
                            toggleSigningAlong(checked === true)
                        }
                    />
                    <span>
                        {t('contracts.template.sign_along')}
                        <span className="block text-xs text-muted-foreground">
                            {t('contracts.template.sign_along_hint')}
                        </span>
                    </span>
                </label>

                {/*
                    The way to the author's own signing page — the ordinary one,
                    the same page a stranger would see. A signature made anywhere
                    else would be recorded differently from the ones it is going
                    to be copied beside.
                */}
                {template.signUrl !== null && (
                    <a
                        href={template.signUrl}
                        className={cn(buttonVariants({ size: 'sm' }), 'w-fit')}
                    >
                        <Pencil className="size-4" />
                        {t('contracts.template.sign_now')}
                    </a>
                )}

                {template.authorSigned && (
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.template.signed')}{' '}
                        {t('contracts.template.signed_locks')}
                    </p>
                )}
            </div>

            <p className="text-xs text-muted-foreground">
                {tChoice('contracts.template.parties', template.partyCount)}
            </p>
        </section>
    );
}

/**
 * Naming the people, and then putting it in the post.
 *
 * Two steps in one panel, and the order between them is the point. The boxes on
 * the document belong to people — "hier tekent de verhuurder, daar de huurder"
 * — so the list is written and saved first, and only then can the editor offer
 * a name beside each box instead of a number. Saving asks nobody anything; it
 * can be done, undone and done again.
 *
 * Only ever shown for a draft, because sending is the step that hands the
 * document to the outside world and there is no second chance at it: the way to
 * change a contract that is already out is to withdraw it and start again,
 * which is deliberately more work and also more honest.
 */
function SendPanel({
    contractId,
    saved,
    members,
    channels,
    workspaceSlug,
}: {
    contractId: string;
    /** Whoever has already been written down, so the form picks up where it left off. */
    saved: SignerRow[];
    members: { id: number; name: string; email: string }[];
    channels: ChannelSummary[];
    workspaceSlug: string;
}) {
    const { t } = useTranslate();
    const { auth } = usePage<{ auth: Auth }>().props;

    const [signers, setSigners] = useState<SignerDraft[]>(() =>
        saved.length > 0
            ? saved.map((row) => ({
                  name: row.name,
                  email: row.email,
                  userId: row.userId,
              }))
            : [{ name: '', email: '', userId: null }],
    );
    const [validForDays, setValidForDays] = useState('14');
    const [notifyChannelId, setNotifyChannelId] = useState('');
    const [busy, setBusy] = useState(false);

    const change = (at: number, patch: Partial<SignerDraft>) =>
        setSigners((current) =>
            current.map((row, index) =>
                index === at ? { ...row, ...patch } : row,
            ),
        );

    const sameAddress = (one: string, other: string) =>
        one.trim().toLowerCase() === other.trim().toLowerCase();

    /*
     * Whether the author is on their own list, read off the list rather than
     * held beside it. A separate boolean would be a second answer to a question
     * the rows already answer — and the two would drift the moment somebody
     * typed their own address into a row by hand.
     */
    const signingTooAt = signers.findIndex((row) =>
        sameAddress(row.email, auth.user.email),
    );

    const signingToo = signingTooAt !== -1;

    /**
     * Put the author at the head of the queue, or take them out again.
     *
     * First rather than appended, and that is worth being firm about: the boxes
     * are drawn against positions, and "de eerste ondertekenaar ben ik" is a
     * rule somebody can hold in their head while laying out a document. Landing
     * halfway down a list of five would not be.
     */
    const toggleSigningToo = (wanted: boolean) =>
        setSigners((current) => {
            const others = current.filter(
                (row) => !sameAddress(row.email, auth.user.email),
            );

            if (!wanted) {
                return others.length > 0
                    ? others
                    : [{ name: '', email: '', userId: null }];
            }

            return [
                {
                    name: auth.user.name,
                    email: auth.user.email,
                    userId: auth.user.id,
                },
                // An untouched blank row would become "vul eerst dit in" the
                // moment somebody ticks the box, which is not what they asked
                // for — they said they are signing, not that they are done.
                ...others.filter(
                    (row) => row.name.trim() !== '' || row.email.trim() !== '',
                ),
            ];
        });

    const ready = signers.every(
        (row) => row.name.trim() !== '' && row.email.trim() !== '',
    );

    const payload = () =>
        signers.map((row) => ({
            name: row.name,
            email: row.email,
            user_id: row.userId,
        }));

    /**
     * Write the list down without asking anybody anything.
     *
     * The step that makes a two-party contract layout possible at all: until
     * these rows exist, the editor has nothing to put in "in te vullen door".
     */
    const saveSigners = () => {
        setBusy(true);

        router.put(
            signersOf.url({ workspace: workspaceSlug, contract: contractId }),
            { signers: payload() } as unknown as Record<string, never>,
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const submit = () => {
        setBusy(true);

        router.post(
            sendContract.url({
                workspace: workspaceSlug,
                contract: contractId,
            }),
            {
                signers: payload(),
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

            <label className="flex items-start gap-2 text-sm">
                <Checkbox
                    checked={signingToo}
                    onCheckedChange={(checked) =>
                        toggleSigningToo(checked === true)
                    }
                />
                <span>
                    {t('contracts.send.sign_myself')}
                    <span className="block text-xs text-muted-foreground">
                        {t('contracts.send.sign_myself_hint')}
                    </span>
                </span>
            </label>

            {signers.map((row, index) => (
                <div key={index} className="flex items-end gap-2">
                    <div className="grid flex-1 gap-1">
                        <Label
                            htmlFor={`signer-name-${index}`}
                            className="text-xs"
                        >
                            {t('contracts.send.name')}
                            {index === signingTooAt && (
                                <span className="ml-1 text-muted-foreground">
                                    {t('contracts.send.you')}
                                </span>
                            )}
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

            {/*
                Saving beside sending rather than instead of it. Somebody
                sending to one person never needs the left-hand button; somebody
                laying out a contract for two parties needs it before they can
                draw the second signature box at all — which is what the hint
                below it says, because a button whose point is invisible is a
                button nobody presses.
            */}
            <div className="flex flex-wrap items-center justify-end gap-2">
                <p className="mr-auto text-xs text-muted-foreground">
                    {t('contracts.send.save_hint')}
                </p>

                <Button
                    variant="outline"
                    onClick={saveSigners}
                    disabled={busy || !ready}
                >
                    <Users className="size-4" />
                    {t('contracts.send.save_signers')}
                </Button>

                <Button onClick={submit} disabled={busy || !ready}>
                    <Send className="size-4" />
                    {t('contracts.send.submit')}
                </Button>
            </div>
        </section>
    );
}

/**
 * Making a fresh draft of this same document, for other people.
 *
 * A dialog rather than a button that acts on the spot, and the name is the whole
 * reason. A contract is named once and never renamed — there is no screen for it
 * — so the moment of copying is the only chance anybody gets to say which
 * exemplaar this is. "(kopie)" is offered as a starting point rather than
 * imposed, because a list of five contracts all ending in "(kopie)" is the same
 * problem one step later.
 *
 * The original is not mentioned in the confirmation on purpose: nothing happens
 * to it, and a dialog that reassures you about a danger that does not exist is a
 * dialog that teaches people to read the next one less carefully.
 */
function DuplicateDialog({
    open,
    onOpenChange,
    contractId,
    title,
    workspaceSlug,
}: {
    open: boolean;
    onOpenChange: (next: boolean) => void;
    contractId: string;
    /** The original's name, which the suggestion is built from. */
    title: string;
    workspaceSlug: string;
}) {
    const { t } = useTranslate();

    const suggestion = t('contracts.detail.duplicate_default', { title });

    /*
     * Null means "nog niets ingetypt", and the suggestion is used instead.
     *
     * Held that way round rather than seeding the state with the suggestion,
     * because of where this dialog ends up: duplicating navigates to the copy,
     * which is the same page component, so this component is never unmounted
     * and its state is never rebuilt. A name seeded once would go on suggesting
     * "Huurovereenkomst (kopie)" while standing on "Huurovereenkomst (kopie)",
     * and the second copy would be offered the first one's name.
     */
    const [name, setName] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const value = name ?? suggestion;

    /** Shut it, and forget what was typed. */
    const close = () => {
        setName(null);
        onOpenChange(false);
    };

    const submit = () => {
        setBusy(true);

        router.post(
            duplicateContract.url({
                workspace: workspaceSlug,
                contract: contractId,
            }),
            { title: value },
            {
                /*
                 * Shut on the way out, and only on success.
                 *
                 * Not in onFinish, which also runs when the server refused the
                 * name — closing then would take the message away along with
                 * the box it belongs to. And not left to the navigation either:
                 * the copy opens on this same page component, so nothing here
                 * unmounts and a dialog nobody closed is a dialog still sitting
                 * over the contract that was just made.
                 */
                onSuccess: close,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (next ? onOpenChange(true) : close())}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('contracts.detail.duplicate_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('contracts.detail.duplicate_explainer')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <Label htmlFor="duplicate-title">
                        {t('contracts.detail.duplicate_name')}
                    </Label>
                    <Input
                        id="duplicate-title"
                        value={value}
                        autoFocus
                        maxLength={200}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.detail.duplicate_name_hint')}
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={close}>
                        {t('settings.actions.cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={busy || value.trim() === ''}
                    >
                        <CopyPlus className="size-4" />
                        {t('contracts.detail.duplicate_confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

                {/*
                    Only for somebody who signed, because they are the only
                    people this is ever sent to — and it is the answer to "heeft
                    hij zijn exemplaar wel gehad", which is the question that
                    reaches for the button below.
                */}
                {signer.state === 'signed' && signer.copySentAt !== null && (
                    <p className="text-xs text-muted-foreground">
                        {t('contracts.detail.copy_sent', {
                            date: moment(signer.copySentAt) ?? '',
                        })}
                    </p>
                )}
            </div>
        </li>
    );
}
