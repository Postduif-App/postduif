import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { GuestBadge } from '@/components/chat/guest-badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { candidates as candidatesRoute, store } from '@/routes/chat/directs';
import type { ChannelMember, ChatWorkspace } from '@/types/chat';

interface NewDirectMessageDialogProps {
    workspace: ChatWorkspace;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const DEBOUNCE_MS = 200;

export function NewDirectMessageDialog({
    workspace,
    open,
    onOpenChange,
}: NewDirectMessageDialogProps) {
    const getInitials = useInitials();
    const [query, setQuery] = useState('');
    const [candidates, setCandidates] = useState<ChannelMember[]>([]);
    const [loading, setLoading] = useState(false);
    const [starting, setStarting] = useState<number | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    candidatesRoute.url(
                        { workspace: workspace.slug },
                        { query: { q: query.trim() } },
                    ),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();
                setCandidates(payload.candidates ?? []);
            } catch (error) {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setCandidates([]);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, query, workspace.slug]);

    /**
     * Closing, and forgetting what was typed.
     *
     * Both go together, and both go through here rather than through an effect
     * watching `open`: the dialog is closed from two places — the overlay and
     * a successful pick — and only one of them travels through the Dialog's own
     * handler. Resetting in an effect covered both, but React counts setting
     * state from an effect as a render it did not need to do.
     *
     * On close rather than on open: leaving the old search term on screen while
     * the dialog fades out looks like it did not take the click.
     */
    const close = () => {
        setQuery('');
        setStarting(null);
        onOpenChange(false);
    };

    /**
     * The server answers with the existing conversation when there is one, so
     * picking somebody twice lands the member back in the same channel instead
     * of creating a second one.
     */
    const start = (member: ChannelMember) => {
        setStarting(member.id);
        router.post(
            store.url({ workspace: workspace.slug }),
            { user_id: member.id },
            {
                onSuccess: () => close(),
                onFinish: () => setStarting(null),
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
                    <DialogTitle>Nieuw gesprek</DialogTitle>
                    <DialogDescription>
                        Kies met wie je wilt praten. Bestaat het gesprek al, dan
                        open je het gewoon opnieuw.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1.5">
                    <Input
                        autoFocus
                        value={query}
                        placeholder="Zoek op naam of @gebruikersnaam…"
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    {/*
                        A plain overflow container rather than Radix's
                        ScrollArea, for the reason spelled out in
                        channel-members-dialog: its viewport sizes with h-full,
                        which resolves to auto inside a max-h-only parent.
                    */}
                    <div className="-mx-1 max-h-72 overflow-y-auto px-1">
                        {candidates.map((member) => (
                            <button
                                key={member.id}
                                type="button"
                                disabled={starting !== null}
                                onClick={() => start(member)}
                                className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left hover:bg-muted disabled:opacity-60"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded bg-muted text-[11px] font-semibold">
                                    {getInitials(member.name)}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center gap-1.5">
                                        <span className="truncate text-sm font-medium">
                                            {member.name}
                                        </span>
                                        {member.isGuest && <GuestBadge />}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        @{member.username}
                                    </span>
                                </span>
                                {starting === member.id && (
                                    <Spinner className="size-4 shrink-0" />
                                )}
                            </button>
                        ))}
                        {!loading && candidates.length === 0 && (
                            <p className="px-2 py-2 text-sm text-muted-foreground">
                                {query.trim() === ''
                                    ? 'Er is nog niemand anders in deze workspace.'
                                    : 'Niemand gevonden.'}
                            </p>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
