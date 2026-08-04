import { router } from '@inertiajs/react';
import { CalendarClock, Check, Hash, Lock, X } from 'lucide-react';
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
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    destroy as destroyBroadcast,
    store as storeBroadcast,
} from '@/routes/chat/broadcast';
import type {
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
} from '@/types/chat';

interface BroadcastDialogProps {
    workspace: ChatWorkspace;
    /** Channels this member can see; only the ones they may post in are offered. */
    channels: ChannelSummary[];
    /** Every tag on a channel they can see. */
    tags: string[];
    /**
     * Announcements this member has waiting, newest moment last.
     *
     * Shown here rather than on a page of their own: a scheduled broadcast
     * belongs to no channel, and this dialog is where somebody who wonders
     * whether they scheduled one already is.
     */
    scheduledBroadcasts: ScheduledBroadcast[];
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
/**
 * An hour from now, rounded up to the next quarter.
 *
 * A moment somebody is likely to keep rather than an empty field: the point of
 * the button is "not now", and the exact minute is theirs to adjust.
 */
function defaultSendAt(): string {
    const when = new Date(Date.now() + 60 * 60 * 1000);

    when.setMinutes(Math.ceil(when.getMinutes() / 15) * 15, 0, 0);

    const pad = (value: number) => String(value).padStart(2, '0');

    return `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}T${pad(when.getHours())}:${pad(when.getMinutes())}`;
}

export function BroadcastDialog({
    workspace,
    channels,
    tags,
    scheduledBroadcasts,
    open,
    onOpenChange,
}: BroadcastDialogProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();
    const [body, setBody] = useState('');
    const [pickedChannels, setPickedChannels] = useState<number[]>([]);
    const [pickedTags, setPickedTags] = useState<string[]>([]);
    const [sending, setSending] = useState(false);

    /*
     * The moment picked for later, or null while sending now. Same shape as the
     * composer's, deliberately: two fields that ask the same question and
     * behave differently is how a screen starts needing a manual.
     */
    const [sendAt, setSendAt] = useState<string | null>(null);
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
        setSendAt(null);
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
                // Absent means now; the endpoint decides which it is.
                ...(sendAt !== null ? { send_at: sendAt } : {}),
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
                    <DialogTitle>{t('actions.broadcast.title')}</DialogTitle>
                    <DialogDescription>
                        {t('actions.broadcast.intro')}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="broadcast-body">
                            {t('actions.broadcast.body_field')}
                        </Label>
                        <textarea
                            id="broadcast-body"
                            value={body}
                            rows={5}
                            maxLength={4000}
                            autoFocus
                            onChange={(event) => setBody(event.target.value)}
                            placeholder={t(
                                'actions.broadcast.body_placeholder',
                            )}
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
                            <Label>{t('actions.broadcast.tags_field')}</Label>
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
                        <Label>{t('actions.broadcast.channels_field')}</Label>
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
                                                {t('actions.broadcast.via_tag')}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                            {available.length === 0 && (
                                <p className="px-2 py-3 text-center text-xs text-muted-foreground">
                                    {t('actions.broadcast.no_channels')}
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

                {/*
                    Above the form, because it answers a question somebody has
                    before they type: did I already schedule this?
                */}
                {scheduledBroadcasts.length > 0 && (
                    <div className="flex flex-col gap-1 rounded-md border p-2">
                        <p className="px-1 text-xs font-medium text-muted-foreground">
                            {t('actions.broadcast.pending_title')}
                        </p>
                        {scheduledBroadcasts.map((waiting) => (
                            <div
                                key={waiting.id}
                                className="flex items-center gap-2 rounded px-1 py-0.5 text-xs"
                            >
                                <span className="min-w-0 flex-1 truncate">
                                    {waiting.body}
                                </span>
                                <span className="shrink-0 text-muted-foreground">
                                    {formats.moment.format(
                                        new Date(waiting.sendAt),
                                    )}
                                </span>
                                <span className="shrink-0 text-muted-foreground">
                                    {tChoice(
                                        'actions.broadcast.pending_channels',
                                        waiting.channels.length,
                                    )}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            destroyBroadcast({
                                                workspace: workspace.slug,
                                                scheduledBroadcast: waiting.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                    title={t('actions.broadcast.withdraw')}
                                    aria-label={t('actions.broadcast.withdraw')}
                                    className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <X className="size-3.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                {/*
                    Only once a moment is picked, like the composer: a date
                    field standing open would suggest that scheduling is the
                    normal way to send an announcement.
                */}
                {sendAt !== null && (
                    <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 text-xs">
                        <CalendarClock className="size-3.5 shrink-0 text-muted-foreground" />
                        <label
                            htmlFor="broadcast-send-at"
                            className="text-muted-foreground"
                        >
                            {t('actions.broadcast.schedule_at')}
                        </label>
                        <input
                            id="broadcast-send-at"
                            type="datetime-local"
                            value={sendAt}
                            onChange={(event) => setSendAt(event.target.value)}
                            className="rounded border bg-background px-1.5 py-0.5 focus-visible:ring-2 focus-visible:outline-none"
                        />
                        <button
                            type="button"
                            onClick={() => setSendAt(null)}
                            title={t('actions.broadcast.schedule_now')}
                            aria-label={t('actions.broadcast.schedule_now')}
                            className="ml-auto rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>
                )}

                <DialogFooter className="sm:items-center sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                        {tChoice('actions.broadcast.reach', reached.size)}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('actions.cancel')}
                        </Button>
                        {/*
                            Offered only while nothing is picked yet; once there
                            is a moment, the field above is the way to change or
                            drop it.
                        */}
                        {sendAt === null && (
                            <Button
                                variant="outline"
                                onClick={() => setSendAt(defaultSendAt())}
                            >
                                <CalendarClock className="size-4" />
                                {t('actions.broadcast.schedule_later')}
                            </Button>
                        )}
                        <Button
                            disabled={
                                sending ||
                                body.trim() === '' ||
                                reached.size === 0
                            }
                            onClick={submit}
                        >
                            {sending && <Spinner />}
                            {/*
                                The button says what it will do. Without this it
                                would read "Versturen" beside a date, which is
                                the one moment somebody needs to be sure.
                            */}
                            {sendAt === null
                                ? t('actions.broadcast.submit')
                                : t('actions.broadcast.schedule_submit')}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
