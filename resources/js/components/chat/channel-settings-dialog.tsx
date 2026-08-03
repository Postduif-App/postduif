import { router } from '@inertiajs/react';
import {
    Archive,
    Check,
    Info,
    Link2,
    MessageSquare,
    Ticket as TicketIcon,
    Trash2,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';

import { ChannelLinksSection } from '@/components/chat/channel-links-section';
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
import { cn } from '@/lib/utils';
import { archive, destroy, update } from '@/routes/chat/channels';
import { update as updateTags } from '@/routes/chat/channels/tags';
import type {
    ActiveChannel,
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

/**
 * The panels, in the order they are worth reading.
 *
 * The description travels with the tab rather than sitting fixed in the header:
 * one sentence covering four unrelated panels ends up describing none of them.
 */
const TABS = [
    {
        id: 'general',
        label: 'Algemeen',
        icon: Info,
        description: 'Hoe dit kanaal heet en waar het over gaat.',
    },
    {
        id: 'messages',
        label: 'Berichten',
        icon: MessageSquare,
        description: 'Bepaal wie er berichten mag plaatsen in dit kanaal.',
    },
    {
        id: 'tickets',
        label: 'Tickets',
        icon: TicketIcon,
        description:
            'Of dit kanaal tickets bijhoudt, wie ze mag aanmaken, en wat daarvan in het gesprek terechtkomt.',
    },
    {
        id: 'links',
        label: 'Knoppen',
        icon: Link2,
        description:
            'Snelkoppelingen naar plekken buiten de app, in een balk boven het gesprek.',
    },
    {
        id: 'webhooks',
        label: 'Webhooks',
        icon: Webhook,
        description: 'Wat er van buitenaf in dit kanaal mag posten.',
    },
] as const;

type TabId = (typeof TABS)[number]['id'];

/**
 * The labels live here rather than travelling with the page: they describe a
 * fixed set of choices, and the server validates against the same enum. What it
 * means for everyone else is spelled out, because "alleen beheerders" alone does
 * not tell you that reacting and threads stay open.
 */
const POSTING_OPTIONS: Option<ChannelPostingPolicy>[] = [
    {
        value: 'everyone',
        label: 'Iedereen in dit kanaal',
        description: 'Een gewoon gesprek: elk lid kan berichten plaatsen.',
    },
    {
        value: 'admins',
        label: 'Alleen beheerders en de kanaalmaker',
        description:
            'Een zendkanaal. Anderen kunnen nog wel reageren met een emoji en in threads antwoorden.',
    },
];

/**
 * Open or private. A DM is deliberately absent: this dialog never opens for one
 * — manageSettings says no — and it is not a visibility anyway.
 */
const VISIBILITY_OPTIONS: Option<'public' | 'private'>[] = [
    {
        value: 'public',
        label: 'Openbaar',
        description:
            'Iedereen in de workspace kan dit kanaal vinden, lezen en zich aansluiten. Gasten niet: die zien alleen wat voor hen is klaargezet.',
    },
    {
        value: 'private',
        label: 'Privé',
        description:
            'Alleen leden zien dit kanaal. Wie er nu in zit blijft erin; de rest raakt het kwijt.',
    },
];

/**
 * How the channel reads. Independent of the visibility above it, and stored in
 * its own column for that reason — see ChannelLayout.
 */
const LAYOUT_OPTIONS: Option<'chat' | 'feed'>[] = [
    {
        value: 'chat',
        label: 'Gesprek',
        description: 'Berichten onder elkaar, zoals een gewoon kanaal.',
    },
    {
        value: 'feed',
        label: 'Feed',
        description:
            'Langere berichten met meer ruimte, zoals een nieuwsbrief of blog.',
    },
];

const TICKET_OPTIONS: Option<ChannelTicketPolicy>[] = [
    {
        value: 'disabled',
        label: 'Geen tickets',
        description: 'Dit kanaal is alleen een gesprek.',
    },
    {
        value: 'everyone',
        label: 'Iedereen in dit kanaal',
        description: 'Een klantkanaal: de klant kan zelf tickets aanmaken.',
    },
    {
        value: 'members',
        label: 'Alleen leden, geen gasten',
        description: 'Gasten lezen de tickets wel, maar maken er geen aan.',
    },
];

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
    const [archiving, setArchiving] = useState(false);

    return (
        <div className="mt-2 flex flex-col gap-3 rounded-lg border p-3">
            <div className="flex flex-col gap-0.5">
                <h3 className="text-sm font-medium">Kanaal archiveren</h3>
                <p className="text-xs text-muted-foreground">
                    Alles blijft leesbaar, maar er kan niets meer geplaatst
                    worden. Het kanaal verdwijnt uit de zijbalk en is terug te
                    halen onder &quot;Gearchiveerd&quot;.
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
                    Archiveren
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
    const [confirming, setConfirming] = useState(false);
    const [typed, setTyped] = useState('');
    const [deleting, setDeleting] = useState(false);

    const matches = typed.trim().toLowerCase() === channel.label.toLowerCase();

    return (
        <div className="mt-2 flex flex-col gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-3">
            <div className="flex flex-col gap-0.5">
                <h3 className="text-sm font-medium text-destructive">
                    Kanaal verwijderen
                </h3>
                <p className="text-xs text-muted-foreground">
                    Alle berichten, threads, tickets en webhooks van dit kanaal
                    gaan mee. Dit is niet terug te draaien.
                </p>
            </div>

            {confirming ? (
                <div className="flex flex-col gap-2">
                    <Label htmlFor="confirm-channel-name" className="text-xs">
                        Typ <span className="font-mono">{channel.label}</span>{' '}
                        om te bevestigen
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
                            Definitief verwijderen
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setConfirming(false);
                                setTyped('');
                            }}
                        >
                            Annuleren
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
                        Kanaal verwijderen
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
        tab.id === 'webhooks'
            ? workspace.features.webhooks
            : tab.id === 'tickets'
              ? workspace.features.tickets
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
        statusAnnouncements !== channel.ticketStatusAnnouncements;

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
                    <DialogTitle>Instellingen van #{channel.label}</DialogTitle>
                    {/*
                        Two lines are reserved: the descriptions differ in
                        length per tab, and without the floor the panel below
                        starts a line higher on the short ones.
                    */}
                    <DialogDescription className="min-h-10">
                        {tabs.find((tab) => tab.id === active)?.description}
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
                        aria-label="Kanaalinstellingen"
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
                                {tab.label}
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
                                    <Label htmlFor="channel-name">Naam</Label>
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
                                        Spaties en hoofdletters worden omgezet
                                        naar streepjes en kleine letters. Links
                                        naar dit kanaal blijven werken, maar een{' '}
                                        <code>#oude-naam</code> in oudere
                                        berichten wordt gewone tekst.
                                    </p>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="channel-topic">
                                        Onderwerp
                                    </Label>
                                    <Input
                                        id="channel-topic"
                                        value={topic}
                                        maxLength={255}
                                        placeholder="Waar gaat dit kanaal over?"
                                        onChange={(event) =>
                                            setTopic(event.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Staat onder de naam bovenaan het
                                        gesprek.
                                    </p>
                                </div>

                                <div className="flex flex-col gap-2">
                                    <h3 className="text-sm font-medium">
                                        Zichtbaarheid
                                    </h3>
                                    <ChoiceGroup
                                        label="Zichtbaarheid"
                                        options={VISIBILITY_OPTIONS}
                                        value={visibility}
                                        onChange={setVisibility}
                                    />

                                    {openingUp && (
                                        <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
                                            Let op: alles wat hier eerder is
                                            gezegd wordt hiermee leesbaar voor
                                            de hele workspace. Dit is niet terug
                                            te draaien door het kanaal weer
                                            privé te maken.
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
                                        Weergave
                                    </h3>
                                    <ChoiceGroup
                                        label="Weergave"
                                        options={LAYOUT_OPTIONS}
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
                                    Wie mag berichten plaatsen
                                </h3>
                                <ChoiceGroup
                                    label="Wie mag berichten plaatsen"
                                    options={POSTING_OPTIONS}
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
                                            Reageren in een thread toestaan
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Uitzetten maakt dit een kanaal dat
                                            aankondigt en niet bespreekt.
                                            Bestaande threads blijven leesbaar.
                                        </p>
                                    </div>
                                </div>
                            </section>
                        )}

                        {active === 'tickets' && (
                            <section className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">
                                    Wie mag tickets aanmaken
                                </h3>
                                <ChoiceGroup
                                    label="Wie mag tickets aanmaken"
                                    options={TICKET_OPTIONS}
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
                                                Meld tickets in het gesprek
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Een kort bericht in het kanaal
                                                zodra een ticket wordt
                                                aangemaakt of gesloten, zodat
                                                wie alleen meeleest het ook
                                                ziet.
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
                                                            Ook bij elke
                                                            statuswijziging
                                                        </Label>
                                                        <p className="text-xs text-muted-foreground">
                                                            Standaard uit: een
                                                            kanaal dat elke stap
                                                            meldt is een kanaal
                                                            dat mensen dempen.
                                                            Aanzetten als het
                                                            werk in het gesprek
                                                            gebeurt en niet op
                                                            het bord.
                                                        </p>
                                                    </div>
                                                </div>
                                            )}
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
                    </div>
                </div>

                <DialogFooter className="border-t px-6 py-4">
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Annuleren
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
                        Opslaan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
