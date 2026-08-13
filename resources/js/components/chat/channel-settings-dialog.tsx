import { router } from '@inertiajs/react';
import {
    Archive,
    Building2,
    Check,
    FileText,
    Info,
    Link2,
    MessageSquare,
    Ticket as TicketIcon,
    Trash2,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';

import { ChannelLinksSection } from '@/components/chat/channel-links-section';
import { ChannelSharesSection } from '@/components/chat/channel-shares-section';
import { ChannelTagsField } from '@/components/chat/channel-tags-field';
import { ChannelWebhooksSection } from '@/components/chat/channel-webhooks-section';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { archive, destroy, update } from '@/routes/chat/channels';
import { update as updateTags } from '@/routes/chat/channels/tags';
import type {
    ActiveChannel,
    ChannelDocumentPolicy,
    ChannelPostingPolicy,
    ChannelTicketPolicy,
    ChatWorkspace,
} from '@/types/chat';

interface ChannelSettingsDialogProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    /** Every tag already in use in the workspace, to suggest from. */
    workspaceTags: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

interface Option<T> {
    value: T;
    label: string;
    description: string;
}

/** The one line lookup, so the option lists below can be built with it. */
type Translate = ReturnType<typeof useTranslate>['t'];

/**
 * The panels, in the order they are worth reading.
 *
 * Only the icon travels with the tab: the label and the description are looked
 * up per id, so a panel added here cannot be forgotten in the lang file — the
 * key is spelled out of the id and the type would not accept a missing one.
 *
 * The description belongs to the tab rather than sitting fixed in the header:
 * one sentence covering four unrelated panels ends up describing none of them.
 */
const TABS = [
    { id: 'general', icon: Info },
    { id: 'messages', icon: MessageSquare },
    { id: 'tickets', icon: TicketIcon },
    { id: 'document', icon: FileText },
    { id: 'links', icon: Link2 },
    { id: 'webhooks', icon: Webhook },
    { id: 'shares', icon: Building2 },
] as const;

type TabId = (typeof TABS)[number]['id'];

/**
 * The choices live here rather than travelling with the page: they describe a
 * fixed set of options, and the server validates against the same enum. What it
 * means for everyone else is spelled out, because "alleen beheerders" alone does
 * not tell you that reacting and threads stay open.
 */
function postingOptions(t: Translate): Option<ChannelPostingPolicy>[] {
    return [
        {
            value: 'everyone',
            label: t('channels.posting.everyone'),
            description: t('channels.posting.everyone_hint'),
        },
        {
            value: 'admins',
            label: t('channels.posting.admins'),
            description: t('channels.posting.admins_hint'),
        },
    ];
}

/**
 * Open or private. A DM is deliberately absent: this dialog never opens for one
 * — manageSettings says no — and it is not a visibility anyway.
 */
function visibilityOptions(t: Translate): Option<'public' | 'private'>[] {
    return [
        {
            value: 'public',
            label: t('channels.visibility.public'),
            description: t('channels.visibility.public_explained'),
        },
        {
            value: 'private',
            label: t('channels.visibility.private'),
            description: t('channels.visibility.private_explained'),
        },
    ];
}

/**
 * How the channel reads. Independent of the visibility above it, and stored in
 * its own column for that reason — see ChannelLayout.
 */
function layoutOptions(t: Translate): Option<'chat' | 'feed'>[] {
    return [
        {
            value: 'chat',
            label: t('channels.layout.chat'),
            description: t('channels.layout.chat_hint'),
        },
        {
            value: 'feed',
            label: t('channels.layout.feed'),
            description: t('channels.layout.feed_hint'),
        },
    ];
}

function ticketOptions(t: Translate): Option<ChannelTicketPolicy>[] {
    return [
        {
            value: 'disabled',
            label: t('channels.tickets.disabled'),
            description: t('channels.tickets.disabled_hint'),
        },
        {
            value: 'everyone',
            label: t('channels.tickets.everyone'),
            description: t('channels.tickets.everyone_hint'),
        },
        {
            value: 'members',
            label: t('channels.tickets.members'),
            description: t('channels.tickets.members_hint'),
        },
    ];
}

function documentOptions(t: Translate): Option<ChannelDocumentPolicy>[] {
    return [
        {
            value: 'disabled',
            label: t('channels.documents.disabled'),
            description: t('channels.documents.disabled_hint'),
        },
        {
            value: 'everyone',
            label: t('channels.documents.everyone'),
            description: t('channels.documents.everyone_hint'),
        },
        {
            value: 'members',
            label: t('channels.documents.members'),
            description: t('channels.documents.members_hint'),
        },
    ];
}

/**
 * One set of mutually exclusive choices.
 *
 * Pulled out of the dialog once there were two of them: the markup carries the
 * whole selected state in class names, and two hand-maintained copies of that
 * would start to differ the first time either is touched.
 */
function ChoiceGroup<T extends string>({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: Option<T>[];
    value: T;
    onChange: (next: T) => void;
}) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="flex flex-col gap-2"
        >
            {options.map((option) => {
                const selected = value === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'flex items-start gap-3 rounded-lg border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                            selected
                                ? 'border-primary/50 bg-primary/5'
                                : 'hover:bg-muted/50',
                        )}
                    >
                        <span
                            className={cn(
                                'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                                selected &&
                                    'border-primary bg-primary text-primary-foreground',
                            )}
                        >
                            {selected && <Check className="size-2.5" />}
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                {option.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {option.description}
                            </span>
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Putting the channel away.
 *
 * Above the delete section and deliberately quieter: this is the reversible
 * one, and somebody who is done with a channel should meet it first. No typing
 * the name — that ceremony belongs to the thing that cannot be undone.
 */
function ArchiveChannelSection({
    workspace,
    channel,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}) {
    const { t } = useTranslate();
    const [archiving, setArchiving] = useState(false);

    return (
        <div className="mt-2 flex flex-col gap-3 rounded-lg border p-3">
            <div className="flex flex-col gap-0.5">
                <h3 className="text-sm font-medium">
                    {t('channels.archive.heading')}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {t('channels.archive.explanation')}
                </p>
            </div>

            <div>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={archiving}
                    onClick={() => {
                        setArchiving(true);

                        router.post(
                            archive.url({
                                workspace: workspace.slug,
                                channel: channel.id,
                            }),
                            {},
                            { onError: () => setArchiving(false) },
                        );
                    }}
                >
                    {archiving && <Spinner />}
                    <Archive className="size-4" />
                    {t('channels.actions.archive')}
                </Button>
            </div>
        </div>
    );
}

/**
 * Deleting the channel, and everything ever said in it.
 *
 * Confirmed by typing the name rather than by a second dialog on top of this
 * one: a dialog over a dialog is dismissed by the same reflex that opened it,
 * and the name has to be read off the header to be typed — which is the pause
 * this button is worth.
 */
function DeleteChannelSection({
    workspace,
    channel,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}) {
    const { t } = useTranslate();
    const [confirming, setConfirming] = useState(false);
    const [typed, setTyped] = useState('');
    const [deleting, setDeleting] = useState(false);

    const matches = typed.trim().toLowerCase() === channel.label.toLowerCase();

    return (
        <div className="mt-2 flex flex-col gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-3">
            <div className="flex flex-col gap-0.5">
                <h3 className="text-sm font-medium text-destructive">
                    {t('channels.delete.heading')}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {t('channels.delete.explanation')}
                </p>
            </div>

            {confirming ? (
                <div className="flex flex-col gap-2">
                    <Label htmlFor="confirm-channel-name" className="text-xs">
                        {t('channels.delete.confirm_lead')}{' '}
                        <span className="font-mono">{channel.label}</span>{' '}
                        {t('channels.delete.confirm_tail')}
                    </Label>
                    <Input
                        id="confirm-channel-name"
                        value={typed}
                        autoComplete="off"
                        onChange={(event) => setTyped(event.target.value)}
                    />
                    <div className="flex gap-2">
                        <Button
                            variant="destructive"
                            size="sm"
                            disabled={!matches || deleting}
                            onClick={() => {
                                setDeleting(true);

                                router.delete(
                                    destroy.url({
                                        workspace: workspace.slug,
                                        channel: channel.id,
                                    }),
                                    // No preserveScroll and no onSuccess
                                    // closing the dialog: the response is a
                                    // redirect to another channel, so this
                                    // whole page goes with it.
                                    { onError: () => setDeleting(false) },
                                );
                            }}
                        >
                            {deleting && <Spinner />}
                            {t('channels.delete.confirm_button')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setConfirming(false);
                                setTyped('');
                            }}
                        >
                            {t('channels.actions.cancel')}
                        </Button>
                    </div>
                </div>
            ) : (
                <div>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 className="size-4" />
                        {t('channels.delete.heading')}
                    </Button>
                </div>
            )}
        </div>
    );
}

export function ChannelSettingsDialog({
    workspace,
    channel,
    workspaceTags,
    open,
    onOpenChange,
}: ChannelSettingsDialogProps) {
    const { t } = useTranslate();
    const [posting, setPosting] = useState<ChannelPostingPolicy>(
        channel.postingPolicy,
    );
    const [tickets, setTickets] = useState<ChannelTicketPolicy>(
        channel.ticketPolicy,
    );
    const [announcements, setAnnouncements] = useState(
        channel.ticketAnnouncements,
    );
    const [statusAnnouncements, setStatusAnnouncements] = useState(
        channel.ticketStatusAnnouncements,
    );
    const [documentPolicy, setDocumentPolicy] = useState<ChannelDocumentPolicy>(
        channel.documentPolicy,
    );
    const [documentAnnouncements, setDocumentAnnouncements] = useState(
        channel.documentAnnouncements,
    );
    const [visibility, setVisibility] = useState(
        channel.type === 'private' ? 'private' : 'public',
    );
    const [tags, setTags] = useState(channel.tags);
    const [layout, setLayout] = useState(channel.layout);
    const [repliesOpen, setRepliesOpen] = useState(channel.repliesOpen);
    const [name, setName] = useState(channel.name ?? '');
    const [topic, setTopic] = useState(channel.topic ?? '');
    const [saving, setSaving] = useState(false);
    const [active, setActive] = useState<TabId>('general');

    /*
     * A tab whose feature the workspace switched off does not appear. The panel
     * behind it is dropped with it: 'active' can only ever hold a tab that was
     * rendered, and 'general' — where it starts — is not one of these.
     */
    const tabs = TABS.filter((tab) =>
        tab.id === 'shares'
            ? workspace.features['shared-channels']
            : tab.id === 'webhooks'
              ? workspace.features.webhooks
              : tab.id === 'tickets'
                ? workspace.features.tickets
                : tab.id === 'document'
                  ? workspace.features.documents
                  : true,
    );

    const reset = () => {
        setVisibility(channel.type === 'private' ? 'private' : 'public');
        setTags(channel.tags);
        setLayout(channel.layout);
        setRepliesOpen(channel.repliesOpen);
        setName(channel.name ?? '');
        setTopic(channel.topic ?? '');
        setPosting(channel.postingPolicy);
        setTickets(channel.ticketPolicy);
        setAnnouncements(channel.ticketAnnouncements);
        setStatusAnnouncements(channel.ticketStatusAnnouncements);
        setDocumentPolicy(channel.documentPolicy);
        setDocumentAnnouncements(channel.documentAnnouncements);

        // The tab is part of what "closed" means: reopening lands on the panel
        // this dialog is usually opened for, not on wherever somebody left off
        // in a session they have since forgotten about.
        setActive('general');
    };

    // Opening up a private channel hands its whole history to the workspace, so
    // it is the one change on this tab that says so before it is saved.
    const openingUp = channel.type === 'private' && visibility === 'public';

    // Compared as one string rather than element by element: the order is what
    // the field shows, so a reordering is a change worth saving too.
    const tagsChanged = tags.join('\u0000') !== channel.tags.join('\u0000');

    const changed =
        tagsChanged ||
        repliesOpen !== channel.repliesOpen ||
        layout !== channel.layout ||
        visibility !== (channel.type === 'private' ? 'private' : 'public') ||
        name.trim() !== (channel.name ?? '') ||
        topic.trim() !== (channel.topic ?? '') ||
        posting !== channel.postingPolicy ||
        tickets !== channel.ticketPolicy ||
        announcements !== channel.ticketAnnouncements ||
        statusAnnouncements !== channel.ticketStatusAnnouncements ||
        documentPolicy !== channel.documentPolicy ||
        documentAnnouncements !== channel.documentAnnouncements;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                // Reopening should show what is actually set, not the choice
                // somebody clicked and then walked away from.
                if (!next) {
                    reset();
                }

                onOpenChange(next);
            }}
        >
            {/*
                Wide rather than tall, and flex rather than the default grid:
                the tab strip takes a column of its own, the panel beside it
                still has to hold a webhook URL without wrapping it into
                something nobody can read, and only that panel scrolls.

                The height is fixed rather than capped: a box that sizes to its
                contents resizes on every tab click, so the tab you are aiming
                for moves out from under the cursor.
            */}
            <DialogContent className="flex h-[min(38rem,85vh)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="border-b px-6 py-4">
                    <DialogTitle>
                        {t('channels.settings.title', {
                            channel: channel.label,
                        })}
                    </DialogTitle>
                    {/*
                        Two lines are reserved: the descriptions differ in
                        length per tab, and without the floor the panel below
                        starts a line higher on the short ones.
                    */}
                    <DialogDescription className="min-h-10">
                        {t(`channels.settings.tabs.${active}_description`)}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex min-h-0 flex-1">
                    {/*
                        Down the side rather than across the top: the list keeps
                        growing, and a row of tabs starts wrapping — at which
                        point the second line reads as something other than a
                        tab. A column has room to spare and names each panel in
                        full.
                    */}
                    <nav
                        role="tablist"
                        aria-orientation="vertical"
                        aria-label={t('channels.settings.tablist')}
                        className="flex w-48 shrink-0 flex-col gap-1 border-r p-3"
                    >
                        {tabs.map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                role="tab"
                                id={`channel-settings-tab-${tab.id}`}
                                aria-selected={active === tab.id}
                                aria-controls={`channel-settings-panel-${tab.id}`}
                                onClick={() => setActive(tab.id)}
                                className={cn(
                                    'flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                    active === tab.id
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                )}
                            >
                                <tab.icon className="size-4 shrink-0" />
                                {t(`channels.settings.tabs.${tab.id}`)}
                            </button>
                        ))}
                    </nav>

                    {/*
                        Only the open panel is mounted, but the state behind all
                        of them lives in this component: switching tabs while
                        halfway through a change must not be the thing that
                        throws it away.
                    */}
                    <div
                        role="tabpanel"
                        id={`channel-settings-panel-${active}`}
                        aria-labelledby={`channel-settings-tab-${active}`}
                        className="min-h-0 flex-1 overflow-y-auto px-6 py-4"
                    >
                        {active === 'general' && (
                            <section className="flex flex-col gap-4">
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="channel-name">
                                        {t('channels.fields.name')}
                                    </Label>
                                    {/*
                                        The hash sits in the field rather than in
                                        the value: it is part of how a channel is
                                        written, not part of its name, and every
                                        member who typed one along would have it
                                        slugged away again on the server.
                                    */}
                                    <div className="flex items-center gap-1 rounded-md border pl-2 focus-within:ring-2">
                                        <span className="text-sm text-muted-foreground">
                                            #
                                        </span>
                                        <Input
                                            id="channel-name"
                                            value={name}
                                            maxLength={80}
                                            onChange={(event) =>
                                                setName(event.target.value)
                                            }
                                            className="border-0 shadow-none focus-visible:ring-0"
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('channels.settings.name_hint_lead')}{' '}
                                        <code>
                                            {t(
                                                'channels.settings.name_hint_example',
                                            )}
                                        </code>{' '}
                                        {t('channels.settings.name_hint_tail')}
                                    </p>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="channel-topic">
                                        {t('channels.fields.topic')}
                                    </Label>
                                    <Input
                                        id="channel-topic"
                                        value={topic}
                                        maxLength={255}
                                        placeholder={t(
                                            'channels.fields.topic_placeholder',
                                        )}
                                        onChange={(event) =>
                                            setTopic(event.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('channels.settings.topic_hint')}
                                    </p>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <h3 className="text-sm font-medium">
                                        {t('channels.visibility.heading')}
                                    </h3>
                                    <ChoiceGroup
                                        label={t('channels.visibility.heading')}
                                        options={visibilityOptions(t)}
                                        value={visibility}
                                        onChange={setVisibility}
                                    />

                                    {openingUp && (
                                        <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                                            {t(
                                                'channels.visibility.opening_up',
                                            )}
                                        </p>
                                    )}
                                </div>

                                <ChannelTagsField
                                    value={tags}
                                    onChange={setTags}
                                    suggestions={workspaceTags}
                                />

                                <div className="flex flex-col gap-2">
                                    <h3 className="text-sm font-medium">
                                        {t('channels.layout.heading')}
                                    </h3>
                                    <ChoiceGroup
                                        label={t('channels.layout.heading')}
                                        options={layoutOptions(t)}
                                        value={layout}
                                        onChange={setLayout}
                                    />
                                </div>

                                {channel.canArchive && (
                                    <ArchiveChannelSection
                                        workspace={workspace}
                                        channel={channel}
                                    />
                                )}

                                {channel.canDelete && (
                                    <DeleteChannelSection
                                        workspace={workspace}
                                        channel={channel}
                                    />
                                )}
                            </section>
                        )}

                        {active === 'messages' && (
                            <section className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">
                                    {t('channels.posting.heading')}
                                </h3>
                                <ChoiceGroup
                                    label={t('channels.posting.heading')}
                                    options={postingOptions(t)}
                                    value={posting}
                                    onChange={setPosting}
                                />

                                {/*
                                    Its own setting rather than part of the
                                    choice above. "Alleen beheerders plaatsen"
                                    and "niemand antwoordt" are different
                                    things, and an announcement channel usually
                                    wants the first without the second.
                                */}
                                <div className="mt-1 flex items-start gap-3 rounded-lg border p-3">
                                    <Checkbox
                                        id="replies-open"
                                        className="mt-0.5"
                                        checked={repliesOpen}
                                        onCheckedChange={(checked) =>
                                            setRepliesOpen(checked === true)
                                        }
                                    />
                                    <div className="min-w-0">
                                        <Label
                                            htmlFor="replies-open"
                                            className="text-sm font-medium"
                                        >
                                            {t('channels.posting.replies_open')}
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'channels.posting.replies_open_hint',
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        )}

                        {active === 'tickets' && (
                            <section className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">
                                    {t('channels.tickets.heading')}
                                </h3>
                                <ChoiceGroup
                                    label={t('channels.tickets.heading')}
                                    options={ticketOptions(t)}
                                    value={tickets}
                                    onChange={setTickets}
                                />

                                {/*
                        Only worth asking once the channel keeps tickets at all.
                        The value stays as it was while hidden, so switching
                        tickets off and on again does not silently flip it.
                    */}
                                {tickets !== 'disabled' && (
                                    <div className="mt-1 flex items-start gap-3 rounded-lg border p-3">
                                        <Checkbox
                                            id="ticket-announcements"
                                            className="mt-0.5"
                                            checked={announcements}
                                            onCheckedChange={(checked) =>
                                                setAnnouncements(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <div className="min-w-0">
                                            <Label
                                                htmlFor="ticket-announcements"
                                                className="text-sm font-medium"
                                            >
                                                {t('channels.tickets.announce')}
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'channels.tickets.announce_hint',
                                                )}
                                            </p>

                                            {/*
                                    Inside the box above rather than beside it:
                                    with announcements off nothing is said at
                                    all, and a second checkbox on the same line
                                    would suggest the two can be set apart.
                                */}
                                            {announcements && (
                                                <div className="mt-3 flex items-start gap-3 border-t pt-3">
                                                    <Checkbox
                                                        id="ticket-status-announcements"
                                                        className="mt-0.5"
                                                        checked={
                                                            statusAnnouncements
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setStatusAnnouncements(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <div className="min-w-0">
                                                        <Label
                                                            htmlFor="ticket-status-announcements"
                                                            className="text-sm font-medium"
                                                        >
                                                            {t(
                                                                'channels.tickets.announce_status',
                                                            )}
                                                        </Label>
                                                        <p className="text-xs text-muted-foreground">
                                                            {t(
                                                                'channels.tickets.announce_status_hint',
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        {active === 'document' && (
                            <section className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">
                                    {t('channels.documents.heading')}
                                </h3>
                                <ChoiceGroup
                                    label={t('channels.documents.heading')}
                                    options={documentOptions(t)}
                                    value={documentPolicy}
                                    onChange={setDocumentPolicy}
                                />

                                {/*
                                    Only worth asking once the channel keeps
                                    documents at all. The value stays as it was
                                    while hidden, so switching documents off and
                                    on again does not silently flip it.
                                */}
                                {documentPolicy !== 'disabled' && (
                                    <div className="mt-1 flex items-start gap-3 rounded-lg border p-3">
                                        <Checkbox
                                            id="document-announcements"
                                            className="mt-0.5"
                                            checked={documentAnnouncements}
                                            onCheckedChange={(checked) =>
                                                setDocumentAnnouncements(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <div className="min-w-0">
                                            <Label
                                                htmlFor="document-announcements"
                                                className="text-sm font-medium"
                                            >
                                                {t(
                                                    'channels.documents.announce',
                                                )}
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'channels.documents.announce_hint',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        {active === 'links' && (
                            <ChannelLinksSection
                                workspace={workspace}
                                channel={channel}
                            />
                        )}

                        {active === 'webhooks' && (
                            <ChannelWebhooksSection
                                workspace={workspace}
                                channel={channel}
                            />
                        )}

                        {active === 'shares' && (
                            <ChannelSharesSection
                                workspace={workspace}
                                channel={channel}
                            />
                        )}
                    </div>
                </div>

                <DialogFooter className="border-t px-6 py-4">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('channels.actions.cancel')}
                    </Button>
                    <Button
                        disabled={saving || !changed}
                        onClick={() => {
                            setSaving(true);

                            const saveChannel = () =>
                                router.patch(
                                    update.url({
                                        workspace: workspace.slug,
                                        channel: channel.id,
                                    }),
                                    {
                                        type: visibility,
                                        layout,
                                        name: name.trim(),
                                        topic: topic.trim(),
                                        posting_policy: posting,
                                        replies_open: repliesOpen,
                                        ticket_policy: tickets,
                                        ticket_announcements: announcements,
                                        ticket_status_announcements:
                                            statusAnnouncements,
                                        document_policy: documentPolicy,
                                        document_announcements:
                                            documentAnnouncements,
                                    },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => onOpenChange(false),
                                        onFinish: () => setSaving(false),
                                    },
                                );

                            /*
                                Tags have an endpoint of their own: they are not
                                a column on the channel but rows in a join
                                table, and the action behind them also clears
                                out labels that end up on nothing.

                                Chained rather than fired alongside. Inertia
                                cancels an in-flight visit when a new one
                                starts, so two requests sent together would mean
                                the first one quietly never happening.
                            */
                            if (tagsChanged) {
                                router.put(
                                    updateTags.url({
                                        workspace: workspace.slug,
                                        channel: channel.id,
                                    }),
                                    { tags },
                                    {
                                        preserveScroll: true,
                                        onSuccess: saveChannel,
                                        // Only on failure: on success the
                                        // channel save takes over and turns it
                                        // off when it finishes.
                                        onError: () => setSaving(false),
                                    },
                                );

                                return;
                            }

                            saveChannel();
                        }}
                    >
                        {saving && <Spinner />}
                        {t('channels.actions.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
