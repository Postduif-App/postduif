import { router } from '@inertiajs/react';
import { Forward, Hash, Lock, MessageSquare, Paperclip } from 'lucide-react';
import { useState } from 'react';

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
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { forward } from '@/routes/chat/messages';
import type { ChannelSummary, ChatMessage, ChatWorkspace } from '@/types/chat';

/**
 * Carrying a message into another conversation.
 *
 * The list offers only channels this member is in: posting somewhere means
 * having joined, and a target that would be refused is worse than one that is
 * not offered. The server checks the same thing again.
 */
export function ForwardDialog({
    workspace,
    channel,
    channels,
    message,
    onClose,
}: {
    workspace: ChatWorkspace;
    /** Where the message is now — never a target for itself. */
    channel: { id: number };
    channels: ChannelSummary[];
    /** The message being forwarded, or null while the dialog is shut. */
    message: ChatMessage | null;
    onClose: () => void;
}) {
    const [target, setTarget] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

    const options = channels.filter(
        (row) => row.isMember && row.id !== channel.id,
    );

    const close = () => {
        setTarget(null);
        setNote('');
        setSending(false);
        onClose();
    };

    return (
        <Dialog
            open={message !== null}
            onOpenChange={(next) => (next ? undefined : close())}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Bericht doorsturen</DialogTitle>
                    <DialogDescription>
                        De tekst gaat mee, met de naam van wie het
                        oorspronkelijk zei. Bestanden blijven achter — die horen
                        bij het oorspronkelijke bericht.
                    </DialogDescription>
                </DialogHeader>

                {message && (
                    <div className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                        <p className="line-clamp-3">{message.body}</p>
                        {message.attachments.length > 0 && (
                            <p className="mt-1 flex items-center gap-1.5 text-xs">
                                <Paperclip className="size-3" />
                                {message.attachments.length === 1
                                    ? '1 bestand gaat mee'
                                    : `${message.attachments.length} bestanden gaan mee`}
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-2">
                    <p className="text-sm font-medium">Naar welk kanaal?</p>

                    {options.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Je zit nog in geen enkel ander kanaal om iets
                            naartoe te sturen.
                        </p>
                    ) : (
                        <ScrollArea className="max-h-52 rounded-lg border">
                            <div className="p-1">
                                {options.map((option) => (
                                    <button
                                        key={option.id}
                                        type="button"
                                        aria-pressed={target === option.id}
                                        onClick={() => setTarget(option.id)}
                                        className={cn(
                                            'flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition-colors',
                                            target === option.id
                                                ? 'bg-primary/10 font-medium text-primary'
                                                : 'hover:bg-muted/60',
                                        )}
                                    >
                                        {option.type === 'private' ? (
                                            <Lock className="size-3.5 shrink-0 text-muted-foreground" />
                                        ) : option.type === 'dm' ? (
                                            <MessageSquare className="size-3.5 shrink-0 text-muted-foreground" />
                                        ) : (
                                            <Hash className="size-3.5 shrink-0 text-muted-foreground" />
                                        )}
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </ScrollArea>
                    )}
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="forward-note">Iets erbij zeggen?</Label>
                    <Input
                        id="forward-note"
                        value={note}
                        maxLength={4000}
                        placeholder="Optioneel"
                        onChange={(event) => setNote(event.target.value)}
                    />
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={close}>
                        Annuleren
                    </Button>
                    <Button
                        disabled={target === null || sending || !message}
                        onClick={() => {
                            if (target === null || !message) {
                                return;
                            }

                            setSending(true);

                            router.post(
                                forward.url({
                                    workspace: workspace.slug,
                                    channel: channel.id,
                                    message: message.id,
                                }),
                                { channel_id: target, note },
                                {
                                    preserveScroll: true,
                                    onSuccess: close,
                                    onError: () => setSending(false),
                                },
                            );
                        }}
                    >
                        {sending && <Spinner />}
                        <Forward className="size-4" />
                        Doorsturen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
