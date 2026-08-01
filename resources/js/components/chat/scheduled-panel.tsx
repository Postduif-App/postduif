import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { fromLocalInput, toLocalInput } from '@/lib/local-datetime';
import { cn } from '@/lib/utils';
import {
    destroy as destroyScheduled,
    update as updateScheduled,
} from '@/routes/chat/channels/scheduled';
import type {
    ActiveChannel,
    ChatWorkspace,
    ScheduledMessage,
} from '@/types/chat';

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

interface ScheduledPanelProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    messages: ScheduledMessage[];
    onClose: () => void;
}

/**
 * What this member still has waiting in this channel.
 *
 * A panel beside the conversation rather than a page: these are messages for
 * this channel, and what they are worth reading against is the conversation
 * they will land in.
 */
export function ScheduledPanel({
    workspace,
    channel,
    messages,
    onClose,
}: ScheduledPanelProps) {
    return (
        <aside className="flex w-[26rem] shrink-0 flex-col border-l">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">Ingepland</h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {messages.length === 1
                            ? '1 bericht wacht nog'
                            : `${messages.length} berichten wachten nog`}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    onClick={onClose}
                    aria-label="Sluiten"
                >
                    <X className="size-4" />
                </Button>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {messages.length === 0 ? (
                    <p className="p-8 text-center text-sm text-muted-foreground">
                        Niets staat klaar voor dit kanaal.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {messages.map((scheduled) => (
                            <ScheduledRow
                                key={scheduled.id}
                                workspace={workspace}
                                channel={channel}
                                scheduled={scheduled}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </aside>
    );
}

function ScheduledRow({
    workspace,
    channel,
    scheduled,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    scheduled: ScheduledMessage;
}) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(scheduled.body);
    const [sendAt, setSendAt] = useState(toLocalInput(scheduled.sendAt));

    const target = {
        workspace: workspace.slug,
        channel: channel.id,
        scheduled_message: scheduled.id,
    };

    const failed = scheduled.failedAt !== null;

    return (
        <li className="px-4 py-3">
            <div className="flex items-center gap-2 text-xs">
                <CalendarClock
                    className={cn(
                        'size-3.5 shrink-0',
                        failed ? 'text-destructive' : 'text-muted-foreground',
                    )}
                />
                <span
                    className={cn(
                        failed
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {MOMENT_FORMAT.format(new Date(scheduled.sendAt))}
                </span>
            </div>

            {/*
                A failure is stated where the moment would be read, because it
                replaces it: this one is not going out at that time, or at all,
                until the author does something.
            */}
            {failed && (
                <p className="mt-1.5 flex items-start gap-1.5 rounded border border-destructive/40 bg-destructive/5 p-2 text-xs text-destructive">
                    <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                    <span>
                        {scheduled.failureReason ??
                            'Dit bericht kon niet verstuurd worden.'}
                    </span>
                </p>
            )}

            {editing ? (
                <div className="mt-2 flex flex-col gap-2">
                    <textarea
                        value={body}
                        rows={3}
                        maxLength={4000}
                        aria-label="Bericht"
                        onChange={(event) => setBody(event.target.value)}
                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <input
                        type="datetime-local"
                        value={sendAt}
                        aria-label="Versturen op"
                        onChange={(event) => setSendAt(event.target.value)}
                        className="rounded-md border bg-background px-2 py-1 text-xs focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            disabled={body.trim() === ''}
                            onClick={() => {
                                setEditing(false);
                                router.patch(
                                    updateScheduled.url(target),
                                    {
                                        body: body.trim(),
                                        send_at: fromLocalInput(sendAt),
                                    },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            Opslaan
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                // Back to what is stored, not to what was
                                // half-typed: cancelling means never mind.
                                setBody(scheduled.body);
                                setSendAt(toLocalInput(scheduled.sendAt));
                                setEditing(false);
                            }}
                        >
                            Annuleren
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="group/row mt-1 flex items-start gap-2">
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="min-w-0 flex-1 rounded text-left text-sm whitespace-pre-wrap transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        {scheduled.body}
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            router.delete(destroyScheduled.url(target), {
                                preserveScroll: true,
                            })
                        }
                        aria-label="Intrekken"
                        title="Intrekken"
                        className="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <Trash2 className="size-3.5" />
                    </button>
                </div>
            )}
        </li>
    );
}
