import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Minus, Plus, Save } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { ContractDocument } from '@/components/chat/contract-document';
import { ContractFieldBox } from '@/components/chat/contract-field-box';
import type { FieldDraft } from '@/components/chat/contract-field-box';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useTranslate } from '@/hooks/use-translate';
import { placeBox, pointerFraction, roundBox } from '@/lib/contract-fields';
import type { FieldBox, RenderedPage } from '@/lib/contract-fields';
import { cn } from '@/lib/utils';
import { fields as saveFields } from '@/routes/chat/contracts';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/** One box, exactly as it came off the row. */
interface SavedField {
    id: number;
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
    type: string;
    label: string;
    isRequired: boolean;
    signerIndex: number | null;
}

interface FieldType {
    value: string;
    label: string;
    isDrawn: boolean;
    /** What size a fresh box of this kind starts at, as fractions of the page. */
    width: number;
    height: number;
}

interface ContractBeingLaidOut {
    id: string;
    title: string;
    message: string | null;
    status: string;
    statusLabel: string;
    pageCount: number;
    expiresAt: string | null;
    /** Where pdf.js fetches the document — a policy-guarded route. */
    sourceUrl: string;
    fields: SavedField[];
    signers: { index: number; name: string }[];
}

interface ContractEditProps {
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
    contract: ContractBeingLaidOut;
    fieldTypes: FieldType[];
    workspaceSlug: string;
}

/**
 * How wide a page is drawn, in CSS pixels, per zoom step.
 *
 * Steps rather than a slider: the useful question is "kan ik de kleine letters
 * lezen", and four answers cover it. A slider would invite fiddling with a
 * number that changes nothing about the document — every box is stored as a
 * fraction, so zoom is purely about the person's eyes.
 */
const ZOOM_STEPS = [520, 720, 960, 1240];

const draftFrom = (field: SavedField): FieldDraft => ({
    id: field.id,
    page: field.page,
    x: field.x,
    y: field.y,
    width: field.width,
    height: field.height,
    type: field.type,
    label: field.label,
    isRequired: field.isRequired,
    signerIndex: field.signerIndex,
});

export default function ContractEdit({
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
    fieldTypes,
    workspaceSlug,
}: ContractEditProps) {
    const { t, tChoice } = useTranslate();

    const [searchOpen, setSearchOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const [drafts, setDrafts] = useState<FieldDraft[]>(() =>
        contract.fields.map(draftFrom),
    );
    const [selected, setSelected] = useState<number | null>(null);
    const [zoom, setZoom] = useState(1);
    const [tool, setTool] = useState(fieldTypes[0]?.value ?? 'text');
    const [saving, setSaving] = useState(false);

    /**
     * How big each page came out, keyed by page number.
     *
     * A ref rather than state, and this is the one that would be easy to get
     * wrong. pdf.js reports a size at the end of every render — which happens
     * on every zoom step, for every page — and putting that in state would
     * re-render the whole document each time a page finished, mid-zoom, for
     * every page in turn.
     *
     * The overlay does not need it in state either: it is handed the size by
     * the renderer at the moment it draws.
     */
    const pageSizes = useRef<Map<number, RenderedPage>>(new Map());

    const rememberPageSize = useCallback((page: number, size: RenderedPage) => {
        pageSizes.current.set(page, size);
    }, []);

    /*
     * A contract that has been sent and signed is frozen — see ContractPolicy.
     * The server refuses the save either way; this is so the page does not
     * invite somebody to make changes it will then throw away.
     */
    const frozen = contract.status !== 'draft' && contract.status !== 'sent';

    const activeType = useMemo(
        () => fieldTypes.find((one) => one.value === tool),
        [fieldTypes, tool],
    );

    /**
     * Put a new box where somebody clicked on a page.
     *
     * The click is read against the page element's own rectangle rather than
     * against the scroll container, because that rectangle is the thing the
     * fractions are relative to — and it is the one that moves when the column
     * is scrolled.
     */
    const addField = (
        event: React.PointerEvent<HTMLDivElement>,
        page: number,
    ) => {
        if (frozen || activeType === undefined) {
            return;
        }

        const at = pointerFraction(
            event.clientX,
            event.clientY,
            event.currentTarget.getBoundingClientRect(),
        );

        const box = placeBox(at.x, at.y, {
            width: activeType.width,
            height: activeType.height,
        });

        setDrafts((current) => {
            setSelected(current.length);

            return [
                ...current,
                {
                    id: null,
                    page,
                    ...box,
                    type: activeType.value,
                    label: activeType.label,
                    isRequired: true,
                    /*
                     * Null rather than 0, which both mean the first signer.
                     * Null is what "ik heb hier niet over nagedacht" looks
                     * like in the column, and on a contract with one signer
                     * that is the honest answer.
                     */
                    signerIndex: null,
                },
            ];
        });
    };

    const changeField = (at: number, change: Partial<FieldDraft>) =>
        setDrafts((current) =>
            current.map((draft, index) =>
                index === at ? { ...draft, ...change } : draft,
            ),
        );

    const moveField = (at: number, box: FieldBox) =>
        setDrafts((current) =>
            current.map((draft, index) =>
                index === at ? { ...draft, ...box } : draft,
            ),
        );

    const removeField = (at: number) => {
        setDrafts((current) => current.filter((_, index) => index !== at));
        setSelected(null);
    };

    const save = () => {
        setSaving(true);

        router.put(
            saveFields.url({ workspace: workspaceSlug, contract: contract.id }),
            {
                /*
                 * Rounded here, once, on the way out. During a drag the
                 * arithmetic stays exact; what the column holds is eight
                 * decimals, and sending more would come back different from
                 * what was sent — which the page would then read as unsaved
                 * changes it does not have.
                 *
                 * Cast at the seam, as the form builder does: nested JSON is
                 * something Inertia carries perfectly well, its
                 * FormDataConvertible type simply does not say so.
                 */
                fields: drafts.map((draft) => ({
                    id: draft.id,
                    page: draft.page,
                    ...roundBox(draft),
                    type: draft.type,
                    label: draft.label,
                    is_required: draft.isRequired,
                    signer_index: draft.signerIndex,
                })) as unknown as Record<string, never>[],
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const chosen = selected === null ? undefined : drafts[selected];

    const userMenu = <UserMenu />;

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
                            {contract.statusLabel} ·{' '}
                            {tChoice(
                                'contracts.editor.page_count',
                                contract.pageCount,
                            )}{' '}
                            ·{' '}
                            {tChoice(
                                'contracts.editor.field_count',
                                drafts.length,
                            )}
                        </p>
                    </div>

                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() =>
                                    setZoom((step) => Math.max(0, step - 1))
                                }
                                disabled={zoom === 0}
                                aria-label={t('contracts.editor.zoom_out')}
                                className="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
                            >
                                <Minus className="size-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    setZoom((step) =>
                                        Math.min(
                                            ZOOM_STEPS.length - 1,
                                            step + 1,
                                        ),
                                    )
                                }
                                disabled={zoom === ZOOM_STEPS.length - 1}
                                aria-label={t('contracts.editor.zoom_in')}
                                className="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
                            >
                                <Plus className="size-4" />
                            </button>
                        </div>

                        <Button onClick={save} disabled={saving || frozen}>
                            <Save className="size-4" />
                            {t('contracts.editor.save')}
                        </Button>
                    </div>
                </header>

                <div className="flex min-h-0 flex-1">
                    {/*
                        The document, in a column that scrolls on its own. The
                        toolbar beside it stays put: reaching for a different
                        kind of box should not mean scrolling back to the top of
                        a twenty-page contract.
                    */}
                    <div className="min-w-0 flex-1 overflow-auto bg-muted/40 p-6">
                        <ContractDocument
                            url={contract.sourceUrl}
                            pageCount={contract.pageCount}
                            pageWidth={ZOOM_STEPS[zoom]}
                            onPageRendered={rememberPageSize}
                            overlay={(page, size) => (
                                <div
                                    className={cn(
                                        'absolute inset-0',
                                        !frozen && 'cursor-crosshair',
                                    )}
                                    data-testid={`contract-overlay-${page}`}
                                    onPointerDown={(event) => {
                                        /*
                                         * Only a click on the page itself, not
                                         * one that bubbled up from a box being
                                         * dragged — those stop propagation.
                                         * Clicking bare page also clears the
                                         * selection, which is how somebody
                                         * gets the handles out of the way.
                                         */
                                        setSelected(null);
                                        addField(event, page);
                                    }}
                                >
                                    {drafts.map((draft, index) =>
                                        draft.page === page ? (
                                            <ContractFieldBox
                                                key={draft.id ?? `new-${index}`}
                                                draft={draft}
                                                page={size}
                                                selected={selected === index}
                                                disabled={frozen}
                                                onSelect={() =>
                                                    setSelected(index)
                                                }
                                                onChange={(box) =>
                                                    moveField(index, box)
                                                }
                                                onRemove={() =>
                                                    removeField(index)
                                                }
                                            />
                                        ) : null,
                                    )}
                                </div>
                            )}
                        />
                    </div>

                    <aside className="w-72 shrink-0 space-y-6 overflow-auto border-l p-4">
                        <div className="space-y-2">
                            <h2 className="text-xs font-semibold text-muted-foreground uppercase">
                                {t('contracts.editor.tool')}
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                {t('contracts.editor.tool_hint')}
                            </p>

                            <div className="grid gap-1">
                                {fieldTypes.map((type) => (
                                    <button
                                        key={type.value}
                                        type="button"
                                        onClick={() => setTool(type.value)}
                                        disabled={frozen}
                                        className={cn(
                                            'rounded-md border px-3 py-2 text-left text-sm transition-colors disabled:opacity-50',
                                            tool === type.value
                                                ? 'border-primary bg-primary/10 text-foreground'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        {type.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {chosen !== undefined && selected !== null && (
                            <div className="space-y-3 border-t pt-4">
                                <h2 className="text-xs font-semibold text-muted-foreground uppercase">
                                    {t('contracts.editor.selected')}
                                </h2>

                                <div className="grid gap-1">
                                    <Label
                                        htmlFor="field-label"
                                        className="text-xs"
                                    >
                                        {t('contracts.editor.field_label')}
                                    </Label>
                                    <Input
                                        id="field-label"
                                        value={chosen.label}
                                        maxLength={200}
                                        disabled={frozen}
                                        onChange={(event) =>
                                            changeField(selected, {
                                                label: event.target.value,
                                            })
                                        }
                                    />
                                </div>

                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={chosen.isRequired}
                                        disabled={frozen}
                                        onCheckedChange={(checked) =>
                                            changeField(selected, {
                                                isRequired: checked === true,
                                            })
                                        }
                                    />
                                    {t('contracts.editor.required')}
                                </label>

                                {/*
                                    Only offered once there is more than one
                                    person to choose between. A contract with a
                                    single signer would otherwise ask a question
                                    with one answer on every box.
                                */}
                                {contract.signers.length > 1 && (
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor="field-signer"
                                            className="text-xs"
                                        >
                                            {t('contracts.editor.for_signer')}
                                        </Label>
                                        <select
                                            id="field-signer"
                                            value={chosen.signerIndex ?? 0}
                                            disabled={frozen}
                                            onChange={(event) =>
                                                changeField(selected, {
                                                    signerIndex: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                        >
                                            {contract.signers.map((signer) => (
                                                <option
                                                    key={signer.index}
                                                    value={signer.index}
                                                >
                                                    {signer.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={frozen}
                                    onClick={() => removeField(selected)}
                                >
                                    {t('contracts.editor.remove_field')}
                                </Button>
                            </div>
                        )}

                        {frozen && (
                            <p className="border-t pt-4 text-xs text-muted-foreground">
                                {t('contracts.editor.frozen')}
                            </p>
                        )}
                    </aside>
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
