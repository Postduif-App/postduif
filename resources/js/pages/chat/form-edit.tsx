import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Check,
    Copy,
    ListChecks,
    Plus,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserMenu } from '@/components/user-menu-content';
import { useClipboard } from '@/hooks/use-clipboard';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    answers as answersRoute,
    close,
    index as formsIndex,
    post as postToChannel,
    reopen,
    share,
    unshare,
    update,
} from '@/routes/chat/forms';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/** One question, exactly as it came off the row. */
interface SavedField {
    id: number;
    /** What a workflow refers to it by. The server hands it out; this reads it. */
    key: string;
    type: string;
    label: string;
    hint: string | null;
    required: boolean;
    options: string[];
}

interface FormBeingBuilt {
    id: string;
    title: string;
    description: string | null;
    /** yyyy-mm-dd, which is what a date input speaks. */
    closesAt: string | null;
    closedAt: string | null;
    allowsMultipleSubmissions: boolean;
    notifyChannelId: number | null;
    state: 'open' | 'closed' | 'expired';
    submissions: number;
    /** Only for somebody who may share it; the token *is* the permission. */
    shareUrl: string | null;
    isShared: boolean;
    fields: SavedField[];
}

interface FieldType {
    value: string;
    label: string;
    /** Whether a question of this kind has choices under it. */
    takesOptions: boolean;
}

interface FormEditProps {
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
    form: FormBeingBuilt;
    fieldTypes: FieldType[];
    /**
     * The slug again, apart from the workspace object. The controller sends it
     * on its own and the routes want it either way round.
     */
    workspaceSlug: string;
    canShare: boolean;
}

/**
 * A question while it is being written.
 *
 * The id is what tells an existing question from a new one — a draft with none
 * is created on save, and one that was dropped from this list is deleted server
 * side. The key rides along unchanged: it is the server's to hand out, and
 * workflows already point at it.
 */
interface Draft {
    id: number | null;
    key: string | null;
    type: string;
    label: string;
    hint: string;
    required: boolean;
    options: string[];
}

const draftFrom = (field: SavedField): Draft => ({
    id: field.id,
    key: field.key,
    type: field.type,
    label: field.label,
    hint: field.hint ?? '',
    required: field.required,
    options: field.options,
});

/**
 * One question, open for editing.
 *
 * Everything about it sits in the same card rather than behind a panel: a form
 * is read top to bottom by whoever fills it in, and it should be written the
 * same way. Order is the array's order, which is what the server saves.
 */
function FieldCard({
    draft,
    at,
    total,
    fieldTypes,
    onChange,
    onMove,
    onRemove,
}: {
    draft: Draft;
    at: number;
    total: number;
    fieldTypes: FieldType[];
    onChange: (change: Partial<Draft>) => void;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslate();

    const type = fieldTypes.find((one) => one.value === draft.type);

    const id = `field-${draft.id ?? `new-${at}`}`;

    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex items-start gap-2">
                <div className="grid flex-1 gap-1">
                    <Label htmlFor={`${id}-label`} className="text-xs">
                        {t('forms.screen.field_label')}
                    </Label>
                    <Input
                        id={`${id}-label`}
                        value={draft.label}
                        maxLength={200}
                        onChange={(event) =>
                            onChange({ label: event.target.value })
                        }
                    />
                </div>

                <div className="flex items-center gap-1 pt-6">
                    <button
                        type="button"
                        onClick={() => onMove(at - 1)}
                        disabled={at === 0}
                        aria-label={t('forms.screen.move_up')}
                        className="text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                    >
                        <ArrowUp className="size-4" />
                    </button>
                    <button
                        type="button"
                        onClick={() => onMove(at + 1)}
                        disabled={at === total - 1}
                        aria-label={t('forms.screen.move_down')}
                        className="text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
                    >
                        <ArrowDown className="size-4" />
                    </button>
                    <button
                        type="button"
                        onClick={onRemove}
                        aria-label={t('forms.screen.remove_field')}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <X className="size-4" />
                    </button>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-type`} className="text-xs">
                        {t('forms.screen.field_type')}
                    </Label>
                    <select
                        id={`${id}-type`}
                        value={draft.type}
                        onChange={(event) =>
                            onChange({
                                type: event.target.value,
                                /*
                                 * Choices belong to the kind of question that
                                 * has them. Keeping them across a change to
                                 * "getal" would leave a list nobody can see and
                                 * the server refuses anyway.
                                 */
                                options: fieldTypes.find(
                                    (one) => one.value === event.target.value,
                                )?.takesOptions
                                    ? draft.options
                                    : [],
                            })
                        }
                        className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    >
                        {fieldTypes.map((one) => (
                            <option key={one.value} value={one.value}>
                                {one.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-1">
                    <Label htmlFor={`${id}-hint`} className="text-xs">
                        {t('forms.screen.field_hint')}
                    </Label>
                    <Input
                        id={`${id}-hint`}
                        value={draft.hint}
                        maxLength={200}
                        onChange={(event) =>
                            onChange({ hint: event.target.value })
                        }
                    />
                </div>
            </div>

            {type?.takesOptions && (
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-options`} className="text-xs">
                        {t('forms.screen.field_options')}
                    </Label>
                    {/*
                        One per line rather than a row of boxes with a plus
                        beside it: writing five choices is typing, and anything
                        that makes it clicking gets in the way.
                    */}
                    <textarea
                        id={`${id}-options`}
                        value={draft.options.join('\n')}
                        rows={4}
                        onChange={(event) =>
                            onChange({
                                options: event.target.value.split('\n'),
                            })
                        }
                        className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('forms.screen.field_options_hint')}
                    </p>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-4">
                <label className="flex items-center gap-2 text-xs">
                    <Checkbox
                        checked={draft.required}
                        onCheckedChange={(checked) =>
                            onChange({ required: checked === true })
                        }
                    />
                    {t('forms.screen.field_required')}
                </label>

                {/*
                    Only for a question that exists: the key is minted server
                    side, and showing an empty one beside a new question would
                    invite somebody to write a workflow against nothing.
                */}
                {draft.key !== null && (
                    <span className="ml-auto text-xs text-muted-foreground">
                        {t('forms.screen.field_key')}:{' '}
                        <code className="font-mono">
                            {`{{ trigger.answers.${draft.key} }}`}
                        </code>
                    </span>
                )}
            </div>
        </div>
    );
}

/**
 * Writing a form: its settings, its questions, and what to do with it.
 *
 * Saved whole rather than question by question. A form is only coherent as a
 * whole — see WorkspaceFormController::update, which takes it in one request
 * for the same reason — so this screen holds the drafts and sends them all at
 * once.
 */
export default function FormEdit({
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
    form,
    fieldTypes,
    workspaceSlug,
    canShare,
}: FormEditProps) {
    useSessionGuard();

    const { t, tChoice } = useTranslate();
    const [copied, copy] = useClipboard();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    /*
     * What the two pickers below choose from. Taken from the shell's own list
     * rather than from a second one beside it: the sidebar and the pickers
     * disagreeing about which channels exist is the kind of difference nobody
     * notices until a form is announced somewhere unexpected.
     */
    const postable = useMemo(
        () => channels.filter((row) => row.type !== 'dm'),
        [channels],
    );

    const [title, setTitle] = useState(form.title);
    const [description, setDescription] = useState(form.description ?? '');
    const [closesAt, setClosesAt] = useState(form.closesAt ?? '');
    const [allowsMultiple, setAllowsMultiple] = useState(
        form.allowsMultipleSubmissions,
    );
    const [notifyChannelId, setNotifyChannelId] = useState(
        form.notifyChannelId === null ? '' : String(form.notifyChannelId),
    );
    const [drafts, setDrafts] = useState<Draft[]>(() =>
        form.fields.map(draftFrom),
    );
    const [channel, setChannel] = useState(
        postable[0] === undefined ? '' : String(postable[0].id),
    );

    const changeField = (at: number, change: Partial<Draft>) =>
        setDrafts((current) =>
            current.map((draft, index) =>
                index === at ? { ...draft, ...change } : draft,
            ),
        );

    const moveField = (at: number, to: number) =>
        setDrafts((current) => {
            if (to < 0 || to >= current.length) {
                return current;
            }

            const next = [...current];
            const [moved] = next.splice(at, 1);
            next.splice(to, 0, moved);

            return next;
        });

    const addField = () =>
        setDrafts((current) => [
            ...current,
            {
                id: null,
                key: null,
                type: fieldTypes[0]?.value ?? 'short-text',
                label: '',
                hint: '',
                required: false,
                options: [],
            },
        ]);

    const save = () =>
        router.put(
            update.url({ workspace: workspaceSlug, form: form.id }),
            {
                title,
                description,
                // An empty box means "no deadline", which is null rather than
                // the empty string a date input hands over.
                closes_at: closesAt === '' ? null : closesAt,
                allows_multiple_submissions: allowsMultiple,
                notify_channel_id:
                    notifyChannelId === '' ? null : Number(notifyChannelId),
                /*
                 * The order of this array is the order of the form, and an
                 * empty choice line is a line somebody left behind while
                 * typing rather than a choice.
                 *
                 * Cast at the seam, as the workflow builder does: what goes
                 * over the wire is nested JSON, which Inertia carries perfectly
                 * well — its FormDataConvertible type simply does not say so.
                 */
                fields: drafts.map((draft) => ({
                    id: draft.id,
                    type: draft.type,
                    label: draft.label,
                    hint: draft.hint === '' ? null : draft.hint,
                    required: draft.required,
                    options: draft.options
                        .map((option) => option.trim())
                        .filter((option) => option !== ''),
                })) as unknown as Record<string, never>[],
            },
            { preserveScroll: true },
        );

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={form.title} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                formsActive
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
                        href={formsIndex.url(workspaceSlug)}
                        aria-label={t('forms.answers_screen.back')}
                        className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {form.title}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {tChoice(
                                'forms.answers_screen.count',
                                form.submissions,
                            )}
                        </p>
                    </div>

                    <div className="ml-auto flex shrink-0 items-center gap-2">
                        <Link
                            href={answersRoute.url({
                                workspace: workspaceSlug,
                                form: form.id,
                            })}
                            className={cn(
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'sm',
                                }),
                            )}
                        >
                            <ListChecks className="size-4" />
                            {t('forms.screen.answers')}
                        </Link>

                        {/*
                            Closing is its own request rather than part of
                            saving: it is the one decision that stops the form
                            taking anything in, and it should not ride along
                            with a change of wording.
                        */}
                        {form.state === 'open' ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        close.url({
                                            workspace: workspaceSlug,
                                            form: form.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('forms.screen.close_form')}
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.delete(
                                        reopen.url({
                                            workspace: workspaceSlug,
                                            form: form.id,
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('forms.screen.reopen_form')}
                            </Button>
                        )}
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                        <div className="grid gap-4">
                            <div className="grid gap-1">
                                <Label htmlFor="form-title">
                                    {t('forms.screen.form_title')}
                                </Label>
                                <Input
                                    id="form-title"
                                    value={title}
                                    maxLength={80}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                />
                            </div>

                            <div className="grid gap-1">
                                <Label htmlFor="form-description">
                                    {t('forms.screen.form_description')}
                                </Label>
                                <textarea
                                    id="form-description"
                                    value={description}
                                    rows={3}
                                    maxLength={1000}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('forms.screen.form_description_hint')}
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="form-closes-at">
                                        {t('forms.screen.closes_at')}
                                    </Label>
                                    <Input
                                        id="form-closes-at"
                                        type="date"
                                        value={closesAt}
                                        onChange={(event) =>
                                            setClosesAt(event.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('forms.screen.closes_at_hint')}
                                    </p>
                                </div>

                                <div className="grid gap-1">
                                    <Label htmlFor="form-notify-channel">
                                        {t('forms.screen.notify_channel')}
                                    </Label>
                                    <select
                                        id="form-notify-channel"
                                        value={notifyChannelId}
                                        onChange={(event) =>
                                            setNotifyChannelId(
                                                event.target.value,
                                            )
                                        }
                                        className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                    >
                                        <option value="">—</option>
                                        {postable.map((one) => (
                                            <option key={one.id} value={one.id}>
                                                #{one.name ?? one.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-muted-foreground">
                                        {t('forms.screen.notify_channel_hint')}
                                    </p>
                                </div>
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={allowsMultiple}
                                    onCheckedChange={(checked) =>
                                        setAllowsMultiple(checked === true)
                                    }
                                />
                                {t('forms.screen.allows_multiple')}
                            </label>
                        </div>

                        <div className="space-y-3">
                            <Label>{t('forms.screen.fields')}</Label>

                            {drafts.map((draft, at) => (
                                <FieldCard
                                    key={draft.id ?? `new-${at}`}
                                    draft={draft}
                                    at={at}
                                    total={drafts.length}
                                    fieldTypes={fieldTypes}
                                    onChange={(change) =>
                                        changeField(at, change)
                                    }
                                    onMove={(to) => moveField(at, to)}
                                    onRemove={() =>
                                        setDrafts((current) =>
                                            current.filter(
                                                (_, index) => index !== at,
                                            ),
                                        )
                                    }
                                />
                            ))}

                            {drafts.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('forms.screen.no_fields')}
                                </p>
                            )}

                            <Button
                                variant="outline"
                                size="sm"
                                onClick={addField}
                            >
                                <Plus className="size-4" />
                                {t('forms.screen.add_field')}
                            </Button>
                        </div>

                        <Button onClick={save}>{t('forms.screen.save')}</Button>

                        {/*
                            Only for somebody who may hand the form to the
                            world. The server sends no URL to anybody else — see
                            the edit controller — so there would be nothing to
                            draw here anyway.
                        */}
                        {canShare && (
                            <div className="space-y-3 rounded-lg border p-4">
                                <Label>{t('forms.screen.share')}</Label>

                                {form.isShared && form.shareUrl !== null && (
                                    <div className="flex items-center gap-2">
                                        <Input
                                            readOnly
                                            value={form.shareUrl}
                                            className="font-mono text-xs"
                                            onFocus={(event) =>
                                                event.target.select()
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                void copy(form.shareUrl ?? '')
                                            }
                                            aria-label={t('forms.screen.copy')}
                                            title={
                                                copied === form.shareUrl
                                                    ? t('forms.screen.copied')
                                                    : t('forms.screen.copy')
                                            }
                                        >
                                            {copied === form.shareUrl ? (
                                                <Check className="size-3.5 text-emerald-600" />
                                            ) : (
                                                <Copy className="size-3.5" />
                                            )}
                                        </Button>
                                    </div>
                                )}

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                share.url({
                                                    workspace: workspaceSlug,
                                                    form: form.id,
                                                }),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {form.isShared
                                            ? t('forms.screen.reshare')
                                            : t('forms.screen.share')}
                                    </Button>

                                    {form.isShared && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.delete(
                                                    unshare.url({
                                                        workspace:
                                                            workspaceSlug,
                                                        form: form.id,
                                                    }),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('forms.screen.unshare')}
                                        </Button>
                                    )}
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    {t('forms.screen.share_hint')}
                                </p>
                            </div>
                        )}

                        {postable.length > 0 && (
                            <div className="space-y-3 rounded-lg border p-4">
                                <Label htmlFor="form-post-channel">
                                    {t('forms.screen.post')}
                                </Label>

                                <div className="flex flex-wrap items-center gap-2">
                                    <select
                                        id="form-post-channel"
                                        value={channel}
                                        onChange={(event) =>
                                            setChannel(event.target.value)
                                        }
                                        aria-label={t(
                                            'forms.screen.post_channel',
                                        )}
                                        className="rounded-md border bg-background px-3 py-2 text-sm"
                                    >
                                        {postable.map((one) => (
                                            <option key={one.id} value={one.id}>
                                                #{one.name ?? one.label}
                                            </option>
                                        ))}
                                    </select>

                                    <Button
                                        variant="outline"
                                        disabled={channel === ''}
                                        onClick={() =>
                                            router.post(
                                                postToChannel.url({
                                                    workspace: workspaceSlug,
                                                    channel: Number(channel),
                                                }),
                                                { form_id: form.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('forms.screen.post')}
                                    </Button>
                                </div>
                            </div>
                        )}
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
                    channels={postable}
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
