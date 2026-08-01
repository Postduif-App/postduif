import { router } from '@inertiajs/react';
import { CornerUpLeft, Pencil, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Composer } from '@/components/chat/composer';
import { MessageBody } from '@/components/chat/message-body';
import {
    ALL_STATUSES,
    TICKET_PRIORITY,
    TICKET_STATUS,
    TicketStatusBadge,
} from '@/components/chat/ticket-status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { update } from '@/routes/chat/tickets';
import { store as storeComment } from '@/routes/chat/tickets/comments';
import type {
    ChannelMember,
    ChatWorkspace,
    OpenTicket,
    TicketPriority,
    TicketStatus,
    TicketTimelineEntry,
} from '@/types/chat';

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'short',
});

/**
 * What the panel needs of the channel a ticket sits in: where to send the
 * changes, and who they can be handed to.
 *
 * Narrowed from ActiveChannel once the workspace-wide ticket list started
 * opening this panel too. That page has no channel on screen — every row comes
 * from a different one — so demanding the whole shape would have meant either a
 * second panel or a fake channel built to satisfy a type.
 */
interface TicketChannel {
    id: number;
    members: ChannelMember[];
}

interface TicketPanelProps {
    workspace: ChatWorkspace;
    channel: TicketChannel;
    ticket: OpenTicket;
    onClose: () => void;
}

/**
 * One line of history, in words.
 *
 * Written out here rather than stored as a sentence on the event: the payload
 * holds what changed, and a phrasing baked in at write time would still be the
 * old phrasing years later — and untranslatable.
 */
function describe(entry: Extract<TicketTimelineEntry, { kind: 'event' }>) {
    const who = entry.author?.name ?? 'Systeem';
    const status = (value: unknown) =>
        TICKET_STATUS[value as TicketStatus]?.label ?? String(value);

    switch (entry.type) {
        case 'created':
            return `${who} maakte dit ticket aan`;
        case 'status_changed':
            return `${who} zette de status op ${status(entry.payload.to)}`;
        case 'priority_changed':
            return `${who} zette de prioriteit op ${
                TICKET_PRIORITY[entry.payload.to as TicketPriority]?.label ??
                entry.payload.to
            }`;
        case 'assigned':
            return `${who} wees dit ticket toe`;
        case 'unassigned':
            return `${who} haalde de toewijzing weg`;
        case 'due_date_changed':
            return entry.payload.to
                ? `${who} zette een streefdatum`
                : `${who} haalde de streefdatum weg`;
        default:
            return `${who} wijzigde iets`;
    }
}

/**
 * One line of the property list: what the field is called, and what it says.
 *
 * A list rather than a row of loose controls. Three bordered boxes side by side
 * read as three unrelated buttons that happen to sit near each other — you have
 * to open each one to find out which is which. With the names down the left the
 * panel answers "what is this ticket" before you touch anything.
 */
function Property({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-2">
            <dt className="w-28 shrink-0 text-xs text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0 flex-1">{children}</dd>
        </div>
    );
}

/**
 * The look of a value that turns out to be editable.
 *
 * Quiet until you go near it: no border, no shadow, just the value — the panel
 * reads as a description of the ticket rather than as a form. The background on
 * hover and while open is what says it can be clicked, and the chevron only
 * appears then, for the same reason.
 */
const PROPERTY_TRIGGER =
    'h-7 w-full justify-between border-transparent bg-transparent px-2 text-sm shadow-none transition-colors hover:bg-muted data-[state=open]:bg-muted [&>svg]:opacity-0 hover:[&>svg]:opacity-60 data-[state=open]:[&>svg]:opacity-60';

/**
 * What the ticket says it is about, turned into a form.
 *
 * Title and description edit together rather than one at a time. They are one
 * sentence split over two fields — a title corrected without its description is
 * how a ticket ends up contradicting itself — and the server takes both in a
 * single patch anyway.
 *
 * No Enter-to-save here, unlike the message editor: a description is written in
 * paragraphs, and a key that submits halfway through the second one loses the
 * rest.
 */
function TicketDescriptionEditor({
    title,
    body,
    onSave,
    onCancel,
}: {
    title: string;
    body: string;
    onSave: (title: string, body: string) => void;
    onCancel: () => void;
}) {
    const [draftTitle, setDraftTitle] = useState(title);
    const [draftBody, setDraftBody] = useState(body);
    const titleRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        titleRef.current?.focus();
        titleRef.current?.setSelectionRange(title.length, title.length);
        // Mount only: re-running this would fight the caret on every keystroke.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const trimmedTitle = draftTitle.trim();
    const trimmedBody = draftBody.trim();

    return (
        <div
            className="flex flex-col gap-2"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onCancel();
                }
            }}
        >
            <Input
                ref={titleRef}
                value={draftTitle}
                maxLength={160}
                aria-label="Titel"
                onChange={(event) => setDraftTitle(event.target.value)}
                className="text-sm font-medium"
            />
            <textarea
                value={draftBody}
                rows={5}
                maxLength={4000}
                aria-label="Omschrijving"
                onChange={(event) => setDraftBody(event.target.value)}
                className="w-full resize-y rounded-md border bg-background px-3 py-2 text-sm leading-relaxed focus-visible:ring-2 focus-visible:outline-none"
            />
            <div className="flex items-center gap-2">
                <Button
                    size="sm"
                    disabled={trimmedTitle === '' || trimmedBody === ''}
                    onClick={() => onSave(trimmedTitle, trimmedBody)}
                >
                    Opslaan
                </Button>
                <Button size="sm" variant="ghost" onClick={onCancel}>
                    Annuleren
                </Button>
                <span className="ml-auto text-xs text-muted-foreground">
                    <kbd className="rounded bg-muted px-1 font-mono">Esc</kbd>{' '}
                    annuleert
                </span>
            </div>
        </div>
    );
}

export function TicketPanel({
    workspace,
    channel,
    ticket,
    onClose,
}: TicketPanelProps) {
    const [sending, setSending] = useState(false);
    const [editing, setEditing] = useState(false);

    const target = {
        workspace: workspace.slug,
        channel: channel.id,
        ticket: ticket.number,
    };

    const patch = (payload: Record<string, string | number | null>) =>
        router.patch(update.url(target), payload, {
            preserveScroll: true,
            preserveState: true,
        });

    /**
     * The Composer holds the draft and clears it itself, so this only has to
     * send what it hands over.
     */
    const send = (body: string) => {
        if (sending) {
            return;
        }

        setSending(true);
        router.post(
            storeComment.url(target),
            { body },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <aside className="flex w-[26rem] shrink-0 flex-col border-l">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold">
                        #{ticket.number} {ticket.title}
                    </h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {ticket.opener?.name ?? 'Onbekend'}
                        {ticket.createdAt &&
                            ` · ${MOMENT_FORMAT.format(new Date(ticket.createdAt))}`}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    onClick={onClose}
                    aria-label="Ticket sluiten"
                >
                    <X className="size-4" />
                </Button>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="flex flex-col gap-3 border-b px-4 py-3">
                    {ticket.canManage ? (
                        <dl className="-mx-2 flex flex-col gap-0.5">
                            <Property label="Status">
                                <Select
                                    value={ticket.status}
                                    onValueChange={(status) =>
                                        patch({ status })
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Status"
                                        className={PROPERTY_TRIGGER}
                                    >
                                        {/*
                                            The badge rather than a SelectValue:
                                            the colour is how a status is read
                                            everywhere else in the app, and a
                                            plain word here would make this the
                                            one place it is not.
                                        */}
                                        <TicketStatusBadge
                                            status={ticket.status}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ALL_STATUSES.map((status) => (
                                            <SelectItem
                                                key={status}
                                                value={status}
                                            >
                                                {TICKET_STATUS[status].label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Property>

                            <Property label="Prioriteit">
                                <Select
                                    value={ticket.priority}
                                    onValueChange={(priority) =>
                                        patch({ priority })
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Prioriteit"
                                        className={PROPERTY_TRIGGER}
                                    >
                                        <span
                                            className={cn(
                                                'truncate',
                                                TICKET_PRIORITY[ticket.priority]
                                                    .className,
                                            )}
                                        >
                                            {
                                                TICKET_PRIORITY[ticket.priority]
                                                    .label
                                            }
                                        </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.keys(
                                                TICKET_PRIORITY,
                                            ) as TicketPriority[]
                                        ).map((priority) => (
                                            <SelectItem
                                                key={priority}
                                                value={priority}
                                            >
                                                {
                                                    TICKET_PRIORITY[priority]
                                                        .label
                                                }
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Property>

                            {/*
                                Assignable to anyone in the channel, and to
                                nobody. The empty choice carries a sentinel
                                rather than an empty string: a Select cannot
                                hold "" as a value.
                            */}
                            <Property label="Toegewezen aan">
                                <Select
                                    value={
                                        ticket.assignee
                                            ? String(ticket.assignee.id)
                                            : 'none'
                                    }
                                    onValueChange={(value) =>
                                        patch({
                                            assigned_to:
                                                value === 'none'
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Toegewezen aan"
                                        className={PROPERTY_TRIGGER}
                                    >
                                        {/*
                                            Nobody is a real answer, not a
                                            missing one, but it is still worth
                                            less ink than a name.
                                        */}
                                        <span
                                            className={cn(
                                                'truncate',
                                                !ticket.assignee &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {ticket.assignee?.name ?? 'Niemand'}
                                        </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            Niemand
                                        </SelectItem>
                                        {channel.members.map((member) => (
                                            <SelectItem
                                                key={member.id}
                                                value={String(member.id)}
                                            >
                                                {member.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Property>
                        </dl>
                    ) : (
                        /*
                            A customer gets the two moves only they can make:
                            saying it is really fixed, and saying it is not. The
                            rest of the fields are about how the work is handled,
                            which is not theirs to decide.
                        */
                        <div className="flex flex-wrap items-center gap-2">
                            <TicketStatusBadge status={ticket.status} />
                            <span className="text-xs text-muted-foreground">
                                {TICKET_STATUS[ticket.status].description}
                            </span>

                            {ticket.canConfirm && (
                                <div className="mt-1 flex w-full gap-2">
                                    {ticket.status !== 'closed' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                patch({ status: 'closed' })
                                            }
                                        >
                                            Dit is opgelost
                                        </Button>
                                    )}
                                    {ticket.status !== 'open' && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                patch({ status: 'open' })
                                            }
                                        >
                                            Toch niet opgelost
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <div className="border-b px-4 py-3">
                    {/*
                        The title is repeated here while editing, because the
                        header truncates to one line: correcting a title in a
                        field you cannot read the whole of is how a typo gets
                        replaced by a different one.
                    */}
                    {editing ? (
                        <TicketDescriptionEditor
                            title={ticket.title}
                            body={ticket.body}
                            onCancel={() => setEditing(false)}
                            onSave={(title, body) => {
                                setEditing(false);

                                if (
                                    title !== ticket.title ||
                                    body !== ticket.body
                                ) {
                                    patch({ title, body });
                                }
                            }}
                        />
                    ) : (
                        <div className="group/body flex items-start gap-2">
                            <p className="min-w-0 flex-1 text-sm whitespace-pre-wrap">
                                {ticket.body}
                            </p>

                            {/*
                                Quiet until you go near it, like the property
                                list above: the panel reads as a description of
                                the ticket rather than as a form.
                            */}
                            {ticket.canEdit && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Titel en omschrijving aanpassen"
                                    onClick={() => setEditing(true)}
                                    className="size-7 shrink-0 opacity-0 transition-opacity group-hover/body:opacity-60 focus-visible:opacity-100"
                                >
                                    <Pencil className="size-3.5" />
                                </Button>
                            )}
                        </div>
                    )}

                    {ticket.source && (
                        <button
                            type="button"
                            onClick={() =>
                                router.visit(
                                    show(
                                        {
                                            workspace: workspace.slug,
                                            channel: ticket.source!.channelId,
                                        },
                                        {
                                            query: {
                                                thread: ticket.source!.id,
                                            },
                                        },
                                    ),
                                )
                            }
                            className="mt-3 flex w-full items-start gap-2 rounded-md border p-2 text-left text-xs text-muted-foreground transition-colors hover:bg-muted/50"
                        >
                            <CornerUpLeft className="mt-0.5 size-3 shrink-0" />
                            <span className="min-w-0">
                                <span className="block font-medium">
                                    Uit een bericht van {ticket.source.author}
                                </span>
                                <span className="block truncate">
                                    {ticket.source.deleted
                                        ? 'Dit bericht is verwijderd'
                                        : ticket.source.snippet}
                                </span>
                            </span>
                        </button>
                    )}
                </div>

                <ol className="flex flex-col">
                    {ticket.timeline.map((entry) =>
                        entry.kind === 'comment' ? (
                            <li
                                key={entry.id}
                                className="border-b px-4 py-3 last:border-b-0"
                            >
                                <p className="text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground">
                                        {entry.author?.name ?? 'Onbekend'}
                                    </span>
                                    {entry.author?.isGuest && ' · gast'}
                                    {entry.createdAt &&
                                        ` · ${MOMENT_FORMAT.format(new Date(entry.createdAt))}`}
                                    {entry.editedAt && ' · bewerkt'}
                                </p>
                                {/*
                                    Through MessageBody with nothing to resolve
                                    against, which leaves exactly the formatting
                                    markers the composer's hint line offers. A
                                    bare "@naam" stays plain text here, the same
                                    answer the picker gives by not appearing.
                                */}
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {entry.deleted ? (
                                        <span className="text-muted-foreground italic">
                                            Deze reactie is ingetrokken
                                        </span>
                                    ) : (
                                        <MessageBody
                                            body={entry.body}
                                            workspace={workspace}
                                            members={[]}
                                            channels={[]}
                                        />
                                    )}
                                </p>
                            </li>
                        ) : (
                            <li
                                key={entry.id}
                                className="px-4 py-1.5 text-xs text-muted-foreground"
                            >
                                {describe(entry)}
                                {entry.createdAt &&
                                    ` · ${MOMENT_FORMAT.format(new Date(entry.createdAt))}`}
                            </li>
                        ),
                    )}
                </ol>
            </div>

            {/*
                The same Composer as the channel, rather than a second textarea
                that keeps its own idea of how Enter behaves and how a field
                looks. Without the pickers, though: a ticket comment is not a
                message, so an "@fenna" in one reaches nobody — see the triggers
                prop.
            */}
            <div className="shrink-0 border-t">
                <Composer
                    placeholder="Reageer op dit ticket"
                    disabled={sending}
                    workspace={workspace}
                    triggers=""
                    onSend={send}
                />
            </div>
        </aside>
    );
}
