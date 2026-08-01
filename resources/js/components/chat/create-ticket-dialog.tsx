import { router } from '@inertiajs/react';
import { useState } from 'react';

import { TICKET_PRIORITY } from '@/components/chat/ticket-status';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/chat/tickets';
import type { ChatMessage, ChatWorkspace, TicketPriority } from '@/types/chat';

/** A channel a ticket can be opened in: where it goes, and what to call it. */
export interface TicketTarget {
    id: number;
    label: string;
}

interface CreateTicketDialogProps {
    workspace: ChatWorkspace;
    /**
     * Where the ticket may go. One entry is a channel already decided — the
     * conversation you are looking at — and the dialog just says so. Several is
     * the workspace-wide list, where nothing is decided yet and picking one is
     * the first thing to do.
     */
    channels: TicketTarget[];
    /**
     * The message being promoted, when the dialog was opened from one. Its text
     * fills the description as a starting point — from there the ticket has a
     * description of its own, so editing the message later cannot quietly
     * rewrite it.
     */
    source: ChatMessage | null;
    /** Whether the priority field is offered at all; guests do not set it. */
    canPrioritise: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** The first line of a message, shortened into something that reads as a title. */
function titleFrom(message: ChatMessage): string {
    const first = message.body.split('\n')[0]?.trim() ?? '';

    return first.length > 80 ? `${first.slice(0, 77)}…` : first;
}

/**
 * Mount this with a key that changes every time it is opened. The same dialog
 * serves both a blank ticket and a promoted message, and a fresh mount is what
 * fills the fields with the message — no effect that writes state on open, and
 * no half-typed ticket left over from the last time.
 */
export function CreateTicketDialog({
    workspace,
    channels,
    source,
    canPrioritise,
    open,
    onOpenChange,
}: CreateTicketDialogProps) {
    // Preselected only when there is nothing to choose. With several channels
    // on offer the field starts empty on purpose: a ticket filed in the wrong
    // channel is worse than one nobody filed, and a default is what makes that
    // happen.
    const [channelId, setChannelId] = useState<number | null>(
        channels.length === 1 ? channels[0].id : null,
    );
    const [title, setTitle] = useState(source ? titleFrom(source) : '');
    const [body, setBody] = useState(source ? source.body : '');
    const [priority, setPriority] = useState<TicketPriority>('normal');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const picking = channels.length > 1;

    const submit = () => {
        if (channelId === null) {
            return;
        }

        setSaving(true);
        router.post(
            store.url({ workspace: workspace.slug, channel: channelId }),
            {
                title: title.trim(),
                body: body.trim(),
                ...(canPrioritise ? { priority } : {}),
                source_message_id: source?.id ?? null,
            },
            {
                onSuccess: () => onOpenChange(false),
                onError: setErrors,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {source ? 'Ticket van dit bericht' : 'Nieuw ticket'}
                    </DialogTitle>
                    <DialogDescription>
                        {source
                            ? `Het bericht blijft staan waar het staat; dit ticket verwijst ernaar terug.`
                            : picking
                              ? 'Kies waar dit ticket bijgehouden wordt; alleen kanalen waar jij tickets mag aanmaken staan erbij.'
                              : `Wordt bijgehouden in #${channels[0].label}, zodat iedereen ziet wat er nog openstaat.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {picking && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="ticket-channel">Kanaal</Label>
                            <Select
                                value={
                                    channelId === null ? '' : String(channelId)
                                }
                                onValueChange={(value) =>
                                    setChannelId(Number(value))
                                }
                            >
                                <SelectTrigger id="ticket-channel">
                                    <SelectValue placeholder="Kies een kanaal" />
                                </SelectTrigger>
                                <SelectContent>
                                    {channels.map((target) => (
                                        <SelectItem
                                            key={target.id}
                                            value={String(target.id)}
                                        >
                                            #{target.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="ticket-title">Titel</Label>
                        <Input
                            id="ticket-title"
                            value={title}
                            maxLength={160}
                            onChange={(event) => setTitle(event.target.value)}
                            placeholder="Waar gaat het over?"
                        />
                        {errors.title && (
                            <p className="text-xs text-destructive">
                                {errors.title}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="ticket-body">Omschrijving</Label>
                        <textarea
                            id="ticket-body"
                            value={body}
                            rows={5}
                            onChange={(event) => setBody(event.target.value)}
                            placeholder="Wat is er aan de hand, en wat heb je al geprobeerd?"
                            className="w-full resize-none rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {errors.body && (
                            <p className="text-xs text-destructive">
                                {errors.body}
                            </p>
                        )}
                    </div>

                    {canPrioritise && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="ticket-priority">Prioriteit</Label>
                            <Select
                                value={priority}
                                onValueChange={(value) =>
                                    setPriority(value as TicketPriority)
                                }
                            >
                                <SelectTrigger id="ticket-priority">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(
                                        Object.keys(
                                            TICKET_PRIORITY,
                                        ) as TicketPriority[]
                                    ).map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {TICKET_PRIORITY[value].label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Annuleren
                    </Button>
                    <Button
                        disabled={
                            saving ||
                            channelId === null ||
                            title.trim() === '' ||
                            body.trim() === ''
                        }
                        onClick={submit}
                    >
                        {saving && <Spinner />}
                        Ticket aanmaken
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
