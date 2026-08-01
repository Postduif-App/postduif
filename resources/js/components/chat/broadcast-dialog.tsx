import { router } from '@inertiajs/react';
import { Check, Hash, Lock } from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { store as storeBroadcast } from '@/routes/chat/broadcast';
import type { ChannelSummary, ChatWorkspace } from '@/types/chat';

interface BroadcastDialogProps {
    workspace: ChatWorkspace;
    /** Channels this member can see; only the ones they may post in are offered. */
    channels: ChannelSummary[];
    /** Every tag on a channel they can see. */
    tags: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * One message, several channels.
 *
 * Channels and tags are picked in the same list rather than in two steps: a tag
 * is shorthand for a set of channels, and somebody sending an announcement
 * thinks in terms of "waar moet dit heen", not in terms of which mechanism
 * expresses it. What each tag currently covers is shown as it is picked, so the
 * shorthand is never a guess.
 *
 * Which channels a tag stands for is worked out again on the server when the
 * message is sent — see BroadcastMessageController — so a channel tagged in the
 * meantime is included rather than missed.
 */
export function BroadcastDialog({
    workspace,
    channels,
    tags,
    open,
    onOpenChange,
}: BroadcastDialogProps) {
    const [body, setBody] = useState('');
    const [pickedChannels, setPickedChannels] = useState<number[]>([]);
    const [pickedTags, setPickedTags] = useState<string[]>([]);
    const [sending, setSending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // A DM is not a channel you announce into, and a channel this member may
    // only read along in would be offered and then refused.
    const available = channels.filter(
        (channel) => channel.type !== 'dm' && channel.isMember,
    );

    const reachedByTag = new Set(
        available
            .filter((channel) =>
                channel.tags?.some((tag) => pickedTags.includes(tag)),
            )
            .map((channel) => channel.id),
    );

    // What the message will actually land in, however it was picked. Counted
    // rather than listed twice: a channel chosen by hand and by tag is one
    // channel.
    const reached = new Set([...pickedChannels, ...reachedByTag]);

    const reset = () => {
        setBody('');
        setPickedChannels([]);
        setPickedTags([]);
        setErrors({});
    };

    const toggleChannel = (id: number) =>
        setPickedChannels((current) =>
            current.includes(id)
                ? current.filter((each) => each !== id)
                : [...current, id],
        );

    const toggleTag = (tag: string) =>
        setPickedTags((current) =>
            current.includes(tag)
                ? current.filter((each) => each !== tag)
                : [...current, tag],
        );

    const submit = () => {
        setSending(true);
        router.post(
            storeBroadcast.url(workspace.slug),
            {
                body: body.trim(),
                channels: pickedChannels,
                tags: pickedTags,
            },
            {
                onSuccess: () => {
                    reset();
                    onOpenChange(false);
                },
                onError: setErrors,
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Bericht naar meerdere kanalen</DialogTitle>
                    <DialogDescription>
                        Elk kanaal krijgt een eigen bericht, zodat een reactie
                        daar blijft waar hij hoort.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="broadcast-body">Bericht</Label>
                        <textarea
                            id="broadcast-body"
                            value={body}
                            rows={5}
                            maxLength={4000}
                            autoFocus
                            onChange={(event) => setBody(event.target.value)}
                            placeholder="Wat wil je laten weten?"
                            className="w-full resize-none rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                        {errors.body && (
                            <p className="text-xs text-destructive">
                                {errors.body}
                            </p>
                        )}
                    </div>

                    {tags.length > 0 && (
                        <div className="flex flex-col gap-1.5">
                            <Label>Tags</Label>
                            <div className="flex flex-wrap gap-1.5">
                                {tags.map((tag) => {
                                    const picked = pickedTags.includes(tag);
                                    const covers = available.filter((channel) =>
                                        channel.tags?.includes(tag),
                                    ).length;

                                    return (
                                        <button
                                            key={tag}
                                            type="button"
                                            aria-pressed={picked}
                                            onClick={() => toggleTag(tag)}
                                            className={cn(
                                                'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                                picked
                                                    ? 'border-primary/50 bg-primary/10'
                                                    : 'text-muted-foreground hover:bg-muted',
                                            )}
                                        >
                                            {tag}
                                            <span className="ml-1.5 opacity-70">
                                                {covers}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    <div className="flex flex-col gap-1.5">
                        <Label>Kanalen</Label>
                        <div className="flex max-h-56 flex-col gap-0.5 overflow-y-auto rounded-lg border p-1">
                            {available.map((channel) => {
                                const byTag = reachedByTag.has(channel.id);
                                const picked = pickedChannels.includes(
                                    channel.id,
                                );

                                return (
                                    <button
                                        key={channel.id}
                                        type="button"
                                        role="checkbox"
                                        aria-checked={picked || byTag}
                                        // Reached through a tag and clicked
                                        // anyway: harmless, and unpicking the
                                        // channel would not undo the tag — so
                                        // the row stays ticked and says why.
                                        onClick={() =>
                                            toggleChannel(channel.id)
                                        }
                                        className={cn(
                                            'flex items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition-colors',
                                            picked || byTag
                                                ? 'bg-primary/5'
                                                : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex size-4 shrink-0 items-center justify-center rounded border',
                                                (picked || byTag) &&
                                                    'border-primary bg-primary text-primary-foreground',
                                            )}
                                        >
                                            {(picked || byTag) && (
                                                <Check className="size-2.5" />
                                            )}
                                        </span>
                                        {channel.type === 'private' ? (
                                            <Lock className="size-3.5 shrink-0 opacity-60" />
                                        ) : (
                                            <Hash className="size-3.5 shrink-0 opacity-60" />
                                        )}
                                        <span className="min-w-0 truncate">
                                            {channel.label}
                                        </span>
                                        {byTag && !picked && (
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                via tag
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                            {available.length === 0 && (
                                <p className="px-2 py-3 text-center text-xs text-muted-foreground">
                                    Je bent nog geen lid van een kanaal waar je
                                    in mag posten.
                                </p>
                            )}
                        </div>
                        {errors.channels && (
                            <p className="text-xs text-destructive">
                                {errors.channels}
                            </p>
                        )}
                    </div>
                </div>

                <DialogFooter className="sm:items-center sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                        {reached.size === 0
                            ? 'Nog geen kanaal gekozen'
                            : reached.size === 1
                              ? 'Gaat naar 1 kanaal'
                              : `Gaat naar ${reached.size} kanalen`}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Annuleren
                        </Button>
                        <Button
                            disabled={
                                sending ||
                                body.trim() === '' ||
                                reached.size === 0
                            }
                            onClick={submit}
                        >
                            {sending && <Spinner />}
                            Versturen
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
