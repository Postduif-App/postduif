import { Head, Link, router } from '@inertiajs/react';
import {
    FileSignature,
    FileText,
    LayoutTemplate,
    Plus,
    Search,
    SearchX,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { readableSize } from '@/lib/file-size';
import { cn } from '@/lib/utils';
import { destroy, index, show, store } from '@/routes/chat/contracts';
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
    /** Answered per row by the policy rather than worked out here. */
    canDelete: boolean;
}

/**
 * One mould, which has no status and so says something else instead.
 *
 * A template is a draft forever — see Contract::isSignable — so "concept" would
 * be true and useless on every row. What the reader actually wants to know is
 * whether it can be used yet, and when it cannot, roughly why not.
 */
interface TemplateRow {
    id: string;
    title: string;
    authorName: string | null;
    createdAt: string | null;
    /** The recipients plus the author, when they sign along. */
    partyCount: number;
    signsAlong: boolean;
    authorSigned: boolean;
    /** Answered by the server, which is the thing that has to refuse. */
    isReadyToSend: boolean;
    canDelete: boolean;
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
    /** The documents kept to be sent again, which are never in the list above. */
    templates: TemplateRow[];
    /** What this list was narrowed by, as the server read it. */
    search: string;
    workspaceSlug: string;
    maxUploadBytes: number;
    maxPages: number;
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
    templates,
    search,
    workspaceSlug,
    maxUploadBytes,
    maxPages,
}: ContractsProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [startOpen, setStartOpen] = useState(false);

    /*
     * The row waiting to be confirmed, rather than a boolean. The dialog has to
     * name the contract somebody is about to throw away — a list where every
     * line says "Contract" and the confirmation says "weet je het zeker" is the
     * one where the wrong one goes.
     */
    const [deleting, setDeleting] = useState<{
        id: string;
        title: string;
        /** Absent on a template, which is never evidence of anything. */
        status?: string;
    } | null>(null);

    /*
     * Which of the two lists is on screen.
     *
     * Held here rather than in the address bar, unlike the search terms beside
     * it. The terms have to survive the round trip because the server is what
     * narrowed the list; which tab is open is a fact about the person looking,
     * and both lists are already in hand.
     */
    const [tab, setTab] = useState<'contracts' | 'templates'>('contracts');

    /*
     * What is in the box, which is not the same as what the list was narrowed
     * by: between a keystroke and the answer landing the two differ, and a field
     * driven straight off the prop would swallow letters typed while a request
     * was still in flight.
     */
    const [query, setQuery] = useState(search);

    /*
     * Asked a quarter of a second after the typing stops rather than per
     * keystroke — every letter is a query across the signers of every contract
     * in the workspace.
     *
     * Compared against the trimmed value on purpose. The server hands back what
     * it trimmed, so measuring the raw field against it would leave "jan " never
     * equal to "jan": one request per answer, forever.
     */
    useEffect(() => {
        const terms = query.trim();

        if (terms === search) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(
                index.url(workspaceSlug),
                terms === '' ? {} : { q: terms },
                {
                    /*
                     * The list and the terms it belongs to. The whole chat shell
                     * beside it — channels, threads, unread counts — did not
                     * change because somebody typed a letter.
                     */
                    only: ['contracts', 'templates', 'search'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 250);

        return () => window.clearTimeout(timer);
    }, [query, search, workspaceSlug]);

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

                    {/*
                        Away on the narrowest screens, where the search box and
                        the new-contract button together leave it no room. The
                        rail already says which page this is.
                    */}
                    <h1 className="hidden text-sm font-semibold sm:block">
                        {t('contracts.list.title')}
                    </h1>

                    <div className="relative ml-auto min-w-0 flex-1 sm:max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />

                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('contracts.list.search')}
                            aria-label={t('contracts.list.search')}
                            className="h-8 pr-8 pl-8"
                        />

                        {query !== '' && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute top-1/2 right-0.5 size-7 -translate-y-1/2 text-muted-foreground"
                                aria-label={t('contracts.list.clear_search')}
                                onClick={() => setQuery('')}
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>

                    <Button
                        className="shrink-0"
                        size="sm"
                        onClick={() => setStartOpen(true)}
                    >
                        <Plus className="size-4" />
                        <span className="hidden sm:inline">
                            {t('contracts.list.new')}
                        </span>
                    </Button>
                </header>

                <div className="min-h-0 flex-1 overflow-auto">
                    <div className="mx-auto max-w-3xl px-4 py-6">
                        {/*
                            The moulds beside the letters rather than mixed in
                            with them. A template is a draft forever, so in one
                            list it would read as a contract somebody forgot to
                            send — and the two are asked different questions:
                            "hoe ver staat het" against "kan hij gebruikt
                            worden".

                            Always both tabs, including when there are no
                            templates at all. The tab is where somebody finds
                            out the feature exists, and hiding it until it is
                            already in use would mean it is only ever found by
                            people who did not need telling.
                        */}
                        <div
                            role="tablist"
                            aria-label={t('contracts.list.title')}
                            className="mb-4 flex items-center gap-1 border-b"
                        >
                            {(['contracts', 'templates'] as const).map(
                                (name) => (
                                    <button
                                        key={name}
                                        type="button"
                                        role="tab"
                                        aria-selected={tab === name}
                                        onClick={() => setTab(name)}
                                        className={cn(
                                            '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                                            tab === name
                                                ? 'border-primary font-medium text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {t(
                                            name === 'contracts'
                                                ? 'contracts.list.tab_contracts'
                                                : 'contracts.list.tab_templates',
                                        )}
                                        <span className="ml-1.5 text-xs text-muted-foreground">
                                            {name === 'contracts'
                                                ? contracts.length
                                                : templates.length}
                                        </span>
                                    </button>
                                ),
                            )}
                        </div>

                        {tab === 'templates' ? (
                            <TemplateList
                                templates={templates}
                                search={search}
                                workspaceSlug={workspaceSlug}
                                onDelete={setDeleting}
                            />
                        ) : contracts.length === 0 ? (
                            /*
                                Two empty states rather than one. "Nog geen
                                contracten" under a search box that has "jan" in
                                it is a lie about the workspace, and the advice
                                that belongs with it — upload een PDF — is not
                                what somebody who is looking for one needs.
                            */
                            <div className="rounded-lg border border-dashed px-6 py-12 text-center">
                                {search === '' ? (
                                    <>
                                        <FileSignature className="mx-auto size-8 text-muted-foreground" />
                                        <p className="mt-3 text-sm font-medium">
                                            {t('contracts.list.empty')}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {t('contracts.list.empty_hint')}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <SearchX className="mx-auto size-8 text-muted-foreground" />
                                        <p className="mt-3 text-sm font-medium">
                                            {t('contracts.list.no_results')}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {t(
                                                'contracts.list.no_results_hint',
                                            )}
                                        </p>
                                    </>
                                )}
                            </div>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {contracts.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex items-center gap-1 pr-2 transition-colors hover:bg-muted/50"
                                    >
                                        <Link
                                            href={show.url({
                                                workspace: workspaceSlug,
                                                contract: row.id,
                                            })}
                                            className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3"
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

                                        {/*
                                            Beside the row rather than inside
                                            it: a button nested in the link
                                            would be a button you cannot press
                                            without also opening the contract.
                                        */}
                                        {row.canDelete && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                                aria-label={t(
                                                    'contracts.detail.delete',
                                                )}
                                                onClick={() => setDeleting(row)}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </main>

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {deleting?.title ?? t('contracts.detail.delete')}
                        </AlertDialogTitle>
                        {/* The heavier sentence for a finished one — see the
                            contract's own page, where the same choice is made. */}
                        <AlertDialogDescription>
                            {t(
                                deleting?.status === 'completed'
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
                            onClick={() => {
                                if (deleting !== null) {
                                    router.delete(
                                        destroy.url({
                                            workspace: workspaceSlug,
                                            contract: deleting.id,
                                        }),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {t('contracts.detail.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <StartContractDialog
                open={startOpen}
                onOpenChange={setStartOpen}
                workspaceSlug={workspaceSlug}
                maxUploadBytes={maxUploadBytes}
                maxPages={maxPages}
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
 * The moulds, which are read differently from the letters.
 *
 * No status column, because a template has none worth showing — it is a draft
 * for as long as it exists. What stands there instead is the one thing somebody
 * scanning this list wants: whether it can be used yet. The line underneath says
 * how many parties it is laid out for and whether the author's own signature is
 * on it, which between them cover most of the reasons it cannot.
 */
function TemplateList({
    templates,
    search,
    workspaceSlug,
    onDelete,
}: {
    templates: TemplateRow[];
    /** So the empty state can tell "geen sjablonen" from "niets gevonden". */
    search: string;
    workspaceSlug: string;
    onDelete: (row: { id: string; title: string }) => void;
}) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    if (templates.length === 0) {
        return (
            <div className="rounded-lg border border-dashed px-6 py-12 text-center">
                {search === '' ? (
                    <>
                        <LayoutTemplate className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('contracts.list.templates_empty')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('contracts.list.templates_empty_hint')}
                        </p>
                    </>
                ) : (
                    <>
                        <SearchX className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('contracts.list.no_results')}
                        </p>
                    </>
                )}
            </div>
        );
    }

    return (
        <ul className="divide-y rounded-lg border">
            {templates.map((row) => (
                <li
                    key={row.id}
                    className="flex items-center gap-1 pr-2 transition-colors hover:bg-muted/50"
                >
                    <Link
                        href={show.url({
                            workspace: workspaceSlug,
                            contract: row.id,
                        })}
                        className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {row.title}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {tChoice(
                                    'contracts.template.parties',
                                    row.partyCount,
                                )}
                                {/*
                                    Only mentioned when the author said they
                                    would sign. Somebody who never ticked it does
                                    not need a line telling them they have not
                                    done a thing they never promised.
                                */}
                                {row.signsAlong && (
                                    <>
                                        {' · '}
                                        {t(
                                            row.authorSigned
                                                ? 'contracts.template.signed'
                                                : 'contracts.template.blockers.signature',
                                        )}
                                    </>
                                )}
                                {row.createdAt !== null && (
                                    <>
                                        {' · '}
                                        {formats.date.format(
                                            new Date(row.createdAt),
                                        )}
                                    </>
                                )}
                            </p>
                        </div>

                        <p
                            className={cn(
                                'shrink-0 text-xs font-medium',
                                row.isReadyToSend
                                    ? 'text-emerald-600'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {t(
                                row.isReadyToSend
                                    ? 'contracts.template.ready'
                                    : 'contracts.template.not_ready',
                            )}
                        </p>
                    </Link>

                    {row.canDelete && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                            aria-label={t('contracts.detail.delete')}
                            onClick={() => onDelete(row)}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </li>
            ))}
        </ul>
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
    maxUploadBytes,
    maxPages,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    maxUploadBytes: number;
    maxPages: number;
}) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    const [title, setTitle] = useState('');
    const [file, setFile] = useState<File | null>(null);

    /*
     * Whether this document is being kept rather than sent, asked here because
     * this is where it is known.
     *
     * Somebody uploading a standard lease knows before they pick the file that
     * this is the mould and not the letter — so the tick sits beside the file
     * rather than waiting on a button somewhere else. See
     * ContractController::store, where the alternative is turned down at greater
     * length.
     */
    const [asTemplate, setAsTemplate] = useState(false);

    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);

    const fileInput = useRef<HTMLInputElement>(null);

    /**
     * Take a file on, wherever it came from — the picker or a drop.
     *
     * The refusal for the wrong type is the server's own sentence rather than a
     * second one written for the browser. Somebody who drops a Word file and
     * then, having missed the first message, uploads it anyway should be told
     * the same thing twice, not two different things once.
     */
    const accept = (chosen: File | null | undefined) => {
        if (!chosen) {
            return;
        }

        if (chosen.type !== 'application/pdf') {
            setError(t('contracts.upload.not-a-pdf'));

            return;
        }

        setError(null);
        setFile(chosen);

        /*
         * The file name as an opening suggestion for the title, and only while
         * the field is still untouched. Nine out of ten of these documents are
         * already called what they are about, and the tenth is a field somebody
         * was going to have to fill in either way.
         */
        setTitle((current) =>
            current.trim() === ''
                ? chosen.name.replace(/\.pdf$/i, '').slice(0, 200)
                : current,
        );
    };

    const clear = () => {
        setFile(null);
        setError(null);

        if (fileInput.current !== null) {
            fileInput.current.value = '';
        }
    };

    const submit = () => {
        if (file === null || title.trim() === '') {
            return;
        }

        setBusy(true);
        setError(null);

        router.post(
            store.url(workspaceSlug),
            {
                title,
                file,
                as_template: asTemplate,
            } as unknown as Record<string, never>,
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

                <div className="grid gap-1.5">
                    <Label htmlFor="contract-file">
                        {t('contracts.list.field_file')}
                    </Label>

                    {/*
                        Hidden but present, and before the label rather than
                        after it: the input is what the keyboard reaches and
                        what opens the picker, and Tailwind's peer selectors
                        only look forwards — so the ring the label draws when
                        that invisible input has focus has to be able to see it.
                    */}
                    <input
                        ref={fileInput}
                        id="contract-file"
                        type="file"
                        accept="application/pdf"
                        className="peer sr-only"
                        onChange={(event) => accept(event.target.files?.[0])}
                    />

                    {file === null ? (
                        <label
                            htmlFor="contract-file"
                            className={cn(
                                'flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors',
                                'hover:border-primary/50 hover:bg-muted/40',
                                'peer-focus-visible:border-primary peer-focus-visible:ring-2 peer-focus-visible:ring-ring/50',
                                dragging && 'border-primary bg-primary/5',
                            )}
                            /*
                                The children do not take pointer events, so
                                dragging across the icon does not read as
                                leaving the box and flicker the highlight off.
                            */
                            onDragOver={(event) => {
                                event.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(event) => {
                                event.preventDefault();
                                setDragging(false);
                                accept(event.dataTransfer.files[0]);
                            }}
                        >
                            <Upload className="pointer-events-none size-6 text-muted-foreground" />
                            <span className="pointer-events-none text-sm font-medium">
                                {t('contracts.list.drop')}
                            </span>
                            <span className="pointer-events-none text-xs text-muted-foreground">
                                {t('contracts.list.drop_or')}
                            </span>
                        </label>
                    ) : (
                        /*
                            min-w-0 is hier geen sierraad. De bestandsnaam is
                            één woord zonder afbreekpunt, en truncate hieronder
                            verbergt hem wel maar maakt hem niet smaller: de
                            min-content van deze regel blijft de volle naam. De
                            dialoog is een grid, en een grid-track met een
                            item van 516px wordt 516px breed — ook al staat de
                            doos zelf op max-w-lg. Dan schuift de hele inhoud,
                            titel en knoppen incluis, rechts naar buiten.

                            min-w-0 haalt die bodem eronder weg, zodat de regel
                            wél tot de trackbreedte krimpt en truncate zijn werk
                            kan doen. Op het kind alleen is het niet genoeg — het
                            moet op de grid-item zelf staan.
                        */
                        <div className="flex min-w-0 items-center gap-3 rounded-lg border bg-muted/30 px-3 py-2.5">
                            <FileText className="size-5 shrink-0 text-muted-foreground" />

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {file.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {readableSize(file.size, formats.number)}
                                </p>
                            </div>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="shrink-0"
                                onClick={() => fileInput.current?.click()}
                            >
                                {t('contracts.list.replace')}
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                aria-label={t('contracts.list.remove')}
                                onClick={clear}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        {t('contracts.list.drop_hint', {
                            size: readableSize(maxUploadBytes, formats.number),
                            pages: tChoice(
                                'contracts.list.drop_pages',
                                maxPages,
                            ),
                        })}
                    </p>
                </div>

                {/*
                    Last in the box, under the file, because it is the least
                    common answer: most uploads are a document going out today,
                    and the person who wants a template is the one who came
                    looking for this tick.
                */}
                <label className="flex items-start gap-2 text-sm">
                    <Checkbox
                        checked={asTemplate}
                        onCheckedChange={(checked) =>
                            setAsTemplate(checked === true)
                        }
                    />
                    <span>
                        {t('contracts.list.as_template')}
                        <span className="block text-xs text-muted-foreground">
                            {t('contracts.list.as_template_hint')}
                        </span>
                    </span>
                </label>

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
