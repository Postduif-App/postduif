import { router } from '@inertiajs/react';
import { CalendarClock, Trash2 } from 'lucide-react';
import { useState } from 'react';

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
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { schedule } from '@/routes/chat/huddles';
import { destroy } from '@/routes/chat/huddles/schedule';
import type {
    ActiveChannel,
    ChannelMember,
    ChatWorkspace,
    ScheduledHuddle,
} from '@/types/chat';

/** How long a huddle is offered to last, in minutes. */
const DURATIONS = [15, 30, 45, 60, 90] as const;

/**
 * Putting a huddle in the channel's diary, and seeing what is already in it.
 *
 * One dialog for both, because they are the same question asked twice: somebody
 * opening this wants to know whether the thing they are about to arrange is
 * already arranged. A separate "upcoming" list somewhere else would be a second
 * place to look before every plan.
 */
export function ScheduleHuddleDialog({
    workspace,
    channel,
    members,
    open,
    onOpenChange,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    /** The people in this channel, to ask. */
    members: ChannelMember[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const formats = useFormats();

    const [title, setTitle] = useState('');
    const [startsAt, setStartsAt] = useState(defaultMoment);
    const [duration, setDuration] = useState<number>(30);
    const [invitees, setInvitees] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const save = () => {
        setSaving(true);
        setError(null);

        router.post(
            schedule.url({ workspace: workspace.slug, channel: channel.id }),
            {
                title: title.trim(),
                /*
                 * Sent with the browser's own offset on it. Unlike a reminder —
                 * where the choices are words and the server works out what
                 * they mean — this is a moment somebody typed into a picker,
                 * and the picker already knows which clock they were reading.
                 */
                starts_at: new Date(startsAt).toISOString(),
                duration_minutes: duration,
                invitees,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.starts_at ??
                            errors.title ??
                            errors.invitees ??
                            null,
                    ),
                onSuccess: () => {
                    setTitle('');
                    setInvitees([]);
                    onOpenChange(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('chat_ui.huddle.schedule.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('chat_ui.huddle.schedule.description')}
                    </DialogDescription>
                </DialogHeader>

                {channel.scheduledHuddles.length > 0 && (
                    <div className="flex flex-col gap-1.5 border-b pb-4">
                        <span className="text-xs font-medium text-muted-foreground">
                            {t('chat_ui.huddle.schedule.upcoming')}
                        </span>

                        {channel.scheduledHuddles.map((upcoming) => (
                            <UpcomingRow
                                key={upcoming.id}
                                workspace={workspace}
                                huddle={upcoming}
                                when={formats.moment.format(
                                    new Date(upcoming.startsAt),
                                )}
                            />
                        ))}
                    </div>
                )}

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="huddle-title">
                            {t('chat_ui.huddle.schedule.title_label')}
                        </Label>
                        <Input
                            id="huddle-title"
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                            placeholder={t(
                                'chat_ui.huddle.schedule.title_placeholder',
                            )}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="huddle-starts-at">
                            {t('chat_ui.huddle.schedule.when_label')}
                        </Label>
                        <Input
                            id="huddle-starts-at"
                            type="datetime-local"
                            value={startsAt}
                            onChange={(event) =>
                                setStartsAt(event.target.value)
                            }
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <span className="text-sm font-medium">
                            {t('chat_ui.huddle.schedule.duration_label')}
                        </span>
                        {/*
                            A row of choices rather than a number field. Nobody
                            arranges a meeting for 37 minutes, and the ones
                            people do arrange are worth one click each.
                        */}
                        <div className="flex flex-wrap gap-1.5">
                            {DURATIONS.map((minutes) => (
                                <Button
                                    key={minutes}
                                    type="button"
                                    size="sm"
                                    variant={
                                        duration === minutes
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setDuration(minutes)}
                                >
                                    {t('chat_ui.huddle.schedule.minutes', {
                                        count: String(minutes),
                                    })}
                                </Button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <span className="text-sm font-medium">
                            {t('chat_ui.huddle.schedule.invitees_label')}
                        </span>
                        {/*
                            Nobody ticked is a real answer and the common one: it
                            means the channel at large, which is what a stand-up
                            in a small channel actually is. Said out loud,
                            because an empty list otherwise reads as unfinished.
                        */}
                        <p className="text-xs text-muted-foreground">
                            {invitees.length === 0
                                ? t('chat_ui.huddle.schedule.invitees_none')
                                : t('chat_ui.huddle.schedule.invitees_hint')}
                        </p>

                        <ul className="flex max-h-40 flex-col gap-0.5 overflow-y-auto">
                            {members.map((member) => (
                                <li key={member.id}>
                                    <label className="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted/60">
                                        <Checkbox
                                            checked={invitees.includes(
                                                member.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                setInvitees((current) =>
                                                    checked === true
                                                        ? [
                                                              ...current,
                                                              member.id,
                                                          ]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !==
                                                                  member.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {member.name}
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {error !== null && (
                        <p className="text-xs text-destructive">{error}</p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('channels.actions.cancel')}
                    </Button>
                    <Button
                        disabled={saving || title.trim() === ''}
                        onClick={save}
                    >
                        <CalendarClock className="size-4" />
                        {t('chat_ui.huddle.schedule.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** One appointment already in the diary, with a way out of it for whoever may. */
function UpcomingRow({
    workspace,
    huddle,
    when,
}: {
    workspace: ChatWorkspace;
    huddle: ScheduledHuddle;
    when: string;
}) {
    const { t } = useTranslate();

    return (
        <div className="flex items-center gap-2 rounded border px-2 py-1.5">
            <CalendarClock className="size-3.5 shrink-0 text-muted-foreground" />

            <span className="min-w-0 flex-1 truncate text-sm">
                {huddle.title}
            </span>

            <span className="shrink-0 text-xs text-muted-foreground">
                {when}
            </span>

            {huddle.canCancel && (
                <button
                    type="button"
                    onClick={() =>
                        router.delete(
                            destroy.url({
                                workspace: workspace.slug,
                                scheduled: huddle.id,
                            }),
                            { preserveScroll: true },
                        )
                    }
                    title={t('chat_ui.huddle.schedule.cancel')}
                    aria-label={t('chat_ui.huddle.schedule.cancel')}
                    className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <Trash2 className="size-3.5" />
                </button>
            )}
        </div>
    );
}

/**
 * What the picker starts on: the next whole half hour.
 *
 * Not "now", which every browser would refuse as already past by the time
 * somebody finished typing a title, and not an empty field, which is one more
 * thing to fill in before the common case works.
 */
function defaultMoment(): string {
    const start = new Date();

    start.setMinutes(start.getMinutes() + 30 - (start.getMinutes() % 30), 0, 0);

    // The value a datetime-local field wants: the reader's own wall clock with
    // no zone on it. Built by hand rather than via toISOString(), which would
    // hand it UTC and quietly shift the time it shows.
    const pad = (value: number): string => String(value).padStart(2, '0');

    return (
        `${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(start.getDate())}` +
        `T${pad(start.getHours())}:${pad(start.getMinutes())}`
    );
}
