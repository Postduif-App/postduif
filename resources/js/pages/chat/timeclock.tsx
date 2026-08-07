import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Clock,
    Pencil,
    Play,
    Square,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { ClockOutDialog } from '@/components/clock-out-dialog';
import type { HoursCalendar as Calendar } from '@/components/hours-calendar';
import { HoursCalendar } from '@/components/hours-calendar';
import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { spokenDuration, useElapsed } from '@/lib/duration';
import { cn } from '@/lib/utils';
import {
    clockIn,
    index as timeclockIndex,
    preference,
} from '@/routes/chat/timeclock';
import { destroy, update } from '@/routes/chat/timeclock/entries';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

interface Entry {
    id: number;
    /** The day it counts towards, on the member's own clock. */
    date: string;
    startedAt: string;
    endedAt: string | null;
    /** Already read on the member's clock, so nothing here converts. */
    startedTime: string;
    endedTime: string | null;
    seconds: number;
    running: boolean;
    corrected: boolean;
    /** Longer than a working day explains — only part of it counts. */
    overLimit: boolean;
}

interface Week {
    from: string;
    until: string;
    seconds: number;
    days: { date: string; seconds: number }[];
    entries: Entry[];
}

interface TimeclockProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    archivedChannels: ArchivedChannel[];
    sections: ChannelSectionRow[];
    inboxUnread: number;
    workspaceTags: string[];
    scheduledBroadcasts: ScheduledBroadcast[];
    workspaces: WorkspaceOption[];
    running: { id: number; startedAt: string; seconds: number } | null;
    week: Week;
    /** The last half year of days, for the chart above the week. */
    calendar: Calendar;
    weeksBack: number;
    maxWeeksBack: number;
    setsStatus: boolean;
    /** Null where this member may not read anybody else's hours. */
    colleagues:
        | {
              id: number;
              name: string;
              seconds: number;
              running: boolean;
              since: string | null;
          }[]
        | null;
}

/**
 * A stored date as a weekday somebody reads.
 *
 * Parsed with a time on it rather than bare: "2026-08-03" alone is read as
 * midnight UTC, which is the day before in every zone west of Greenwich and
 * would silently label every row wrong for whoever sits in one.
 */
function readable(date: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T12:00:00`));
}

export default function TimeclockPage({
    workspace,
    channels,
    directMessages,
    activeThreads,
    archivedChannels,
    sections,
    inboxUnread,
    workspaceTags,
    scheduledBroadcasts,
    workspaces,
    running,
    week,
    calendar,
    weeksBack,
    maxWeeksBack,
    setsStatus,
    colleagues,
}: TimeclockProps) {
    useSessionGuard();

    const { t } = useTranslate();
    const { locale, errors } = usePage<{ locale: string }>().props;

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const [clockingOut, setClockingOut] = useState(false);
    const [editing, setEditing] = useState<Entry | null>(null);
    const [removing, setRemoving] = useState<Entry | null>(null);

    const elapsed = useElapsed(running?.startedAt ?? null);

    const today = new Date().toISOString().slice(0, 10);
    const todaySeconds =
        week.days.find((day) => day.date === today)?.seconds ?? 0;

    // The longest day of the week decides how full the bars read. Against the
    // week's own maximum rather than a fixed eight hours: a week of four-hour
    // days should still look like a week, not like a row of stubs.
    const busiest = Math.max(...week.days.map((day) => day.seconds), 1);

    const goToWeek = (weeks: number) =>
        router.get(
            timeclockIndex.url(
                workspace.slug,
                weeks === 0 ? {} : { query: { weeks } },
            ),
            {},
            { preserveScroll: true },
        );

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={t('timeclock.title')} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                timeclockActive
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={userMenu}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <ChannelMenuButton />
                    <Clock className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('timeclock.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('timeclock.description', {
                                workspace: workspace.name,
                            })}
                        </p>
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto">
                    <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                        {/*
                    The clock itself, above the week it produced. Whichever week
                    is being read: somebody looking back at March still has to
                    be able to clock out of today.
                */}
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    {running
                                        ? t('timeclock.running_since', {
                                              time: new Date(
                                                  running.startedAt,
                                              ).toLocaleTimeString(locale, {
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              }),
                                          })
                                        : t('timeclock.not_running')}
                                </p>
                                <p className="font-mono text-2xl tabular-nums">
                                    {running
                                        ? spokenDuration(elapsed)
                                        : spokenDuration(todaySeconds)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t('timeclock.today')}:{' '}
                                    {spokenDuration(todaySeconds)}
                                </p>
                            </div>

                            <Button
                                variant={running ? 'destructive' : 'default'}
                                onClick={() => {
                                    // Stopping is asked about, starting is not
                                    // — see ClockOutDialog for why the two
                                    // differ.
                                    if (running) {
                                        setClockingOut(true);

                                        return;
                                    }

                                    router.post(
                                        clockIn.url(workspace.slug),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                {running ? (
                                    <Square className="size-4" />
                                ) : (
                                    <Play className="size-4" />
                                )}
                                {running
                                    ? t('timeclock.clock_out')
                                    : t('timeclock.clock_in')}
                            </Button>
                        </div>

                        {/*
                            The half year above the week it belongs to, so the
                            eye lands on the shape of the months first and then
                            on the days somebody came for.
                        */}
                        <HoursCalendar
                            calendar={calendar}
                            weeksBack={weeksBack}
                            onSelectWeek={goToWeek}
                            locale={locale}
                        />

                        <section className="space-y-4">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="text-sm font-semibold">
                                    {weeksBack === 0
                                        ? t('timeclock.this_week')
                                        : t('timeclock.week_of', {
                                              date: readable(week.from, locale),
                                          })}
                                </h2>

                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-sm tabular-nums">
                                        {spokenDuration(week.seconds)}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(
                                            'timeclock.previous_week',
                                        )}
                                        disabled={weeksBack >= maxWeeksBack}
                                        onClick={() => goToWeek(weeksBack + 1)}
                                    >
                                        <ChevronLeft className="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('timeclock.next_week')}
                                        disabled={weeksBack === 0}
                                        onClick={() => goToWeek(weeksBack - 1)}
                                    >
                                        <ChevronRight className="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <div className="space-y-1 rounded-lg border p-4">
                                {week.days.map((day) => (
                                    <div
                                        key={day.date}
                                        className="flex items-center gap-3 text-sm"
                                    >
                                        <span
                                            className={cn(
                                                'w-24 shrink-0 text-muted-foreground',
                                                day.date === today &&
                                                    'font-medium text-foreground',
                                            )}
                                        >
                                            {readable(day.date, locale)}
                                        </span>
                                        <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                            <span
                                                className="block h-full rounded-full bg-primary"
                                                style={{
                                                    width: `${(day.seconds / busiest) * 100}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="w-16 shrink-0 text-right font-mono text-muted-foreground tabular-nums">
                                            {day.seconds === 0
                                                ? t('timeclock.no_hours_yet')
                                                : spokenDuration(day.seconds)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="space-y-2">
                            {week.entries.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {t('timeclock.empty')}
                                </p>
                            )}

                            {week.entries.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border px-4 py-3 text-sm"
                                >
                                    <span className="w-24 shrink-0 text-muted-foreground">
                                        {readable(entry.date, locale)}
                                    </span>
                                    <span className="font-mono tabular-nums">
                                        {entry.startedTime} –{' '}
                                        {entry.endedTime ?? '…'}
                                    </span>
                                    <span className="font-mono text-muted-foreground tabular-nums">
                                        {spokenDuration(entry.seconds)}
                                    </span>

                                    {entry.running && (
                                        <span className="text-xs text-muted-foreground">
                                            {t('timeclock.still_running')}
                                        </span>
                                    )}
                                    {entry.corrected && (
                                        <span className="text-xs text-muted-foreground">
                                            {t('timeclock.corrected')}
                                        </span>
                                    )}

                                    <span className="ml-auto flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('timeclock.edit')}
                                            onClick={() => setEditing(entry)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('timeclock.delete')}
                                            onClick={() => setRemoving(entry)}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </span>

                                    {entry.overLimit && (
                                        <p className="w-full text-xs text-muted-foreground">
                                            {t('timeclock.over_limit', {
                                                hours: 16,
                                            })}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </section>

                        {/*
                    Colleagues, only where the workspace handed out the right to
                    look. Null and empty say different things — nobody worked
                    versus this is not yours to read — so only the first of them
                    draws a section at all.
                */}
                        {colleagues !== null && (
                            <section className="space-y-3">
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        {t('timeclock.colleagues.title')}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {t('timeclock.colleagues.explanation')}
                                    </p>
                                </div>

                                {colleagues.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('timeclock.colleagues.empty')}
                                    </p>
                                ) : (
                                    <div className="divide-y rounded-lg border">
                                        {colleagues.map((colleague) => (
                                            <div
                                                key={colleague.id}
                                                className="flex items-center gap-3 px-4 py-2 text-sm"
                                            >
                                                <span className="flex-1 truncate">
                                                    {colleague.name}
                                                </span>
                                                {colleague.running && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {t(
                                                            'timeclock.colleagues.clocked_in',
                                                        )}
                                                    </span>
                                                )}
                                                <span className="font-mono text-muted-foreground tabular-nums">
                                                    {spokenDuration(
                                                        colleague.seconds,
                                                    )}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}

                        <section className="space-y-2 rounded-lg border p-4">
                            <h2 className="text-sm font-semibold">
                                {t('timeclock.preference.title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('timeclock.preference.explanation')}
                            </p>
                            <div className="flex items-center gap-2 pt-1">
                                <Checkbox
                                    id="sets-status"
                                    checked={setsStatus}
                                    onCheckedChange={(checked) =>
                                        router.patch(
                                            preference.url(workspace.slug),
                                            { setsStatus: checked === true },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="sets-status"
                                    className="font-normal"
                                >
                                    {t('timeclock.preference.label')}
                                </Label>
                            </div>
                        </section>
                    </div>
                </div>
            </main>

            {running && (
                <ClockOutDialog
                    runningSince={running.startedAt}
                    workspaceSlug={workspace.slug}
                    open={clockingOut}
                    onOpenChange={setClockingOut}
                />
            )}

            {/*
                Keyed by the stretch it edits, so opening another row mounts a
                fresh form rather than handing the previous one a new subject.
            */}
            {editing !== null && (
                <EditDialog
                    key={editing.id}
                    entry={editing}
                    workspaceSlug={workspace.slug}
                    errors={errors}
                    onClose={() => setEditing(null)}
                />
            )}

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('timeclock.delete_question')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('timeclock.delete_explanation')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('timeclock.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (removing) {
                                    router.delete(
                                        destroy.url({
                                            workspace: workspace.slug,
                                            timeEntry: removing.id,
                                        }),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {t('timeclock.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <SearchDialog
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                actions={{
                    onCreateChannel: workspace.canCreateChannel
                        ? () => setCreateOpen(true)
                        : undefined,
                    onStartDirectMessage: workspace.canStartDirectMessage
                        ? () => setDirectOpen(true)
                        : undefined,
                    onInvitePeople: workspace.canInvite
                        ? () => setInviteOpen(true)
                        : undefined,
                    onBroadcast: workspace.canBroadcastToChannels
                        ? () => setBroadcastOpen(true)
                        : undefined,
                }}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />

            <CreateChannelDialog
                workspace={workspace}
                open={createOpen}
                onOpenChange={setCreateOpen}
            />

            {workspace.canStartDirectMessage && (
                <NewDirectMessageDialog
                    workspace={workspace}
                    open={directOpen}
                    onOpenChange={setDirectOpen}
                />
            )}

            {workspace.canInvite && (
                <InvitePeopleDialog
                    workspace={workspace}
                    channels={channels.filter((row) => row.type !== 'dm')}
                    open={inviteOpen}
                    onOpenChange={setInviteOpen}
                />
            )}

            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                scheduledBroadcasts={scheduledBroadcasts}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />
        </div>
    );
}

/**
 * Correcting one stretch: a date and two times.
 *
 * One date rather than two, even though a night shift ends on the next day. The
 * date is the day the shift *belongs* to — which is the same rule the totals
 * use — and an end time that reads earlier than the start is how "and then it
 * went past midnight" is said. Asking for a second date would be asking people
 * to state a thing the form can work out.
 */
function EditDialog({
    entry,
    workspaceSlug,
    errors,
    onClose,
}: {
    entry: Entry;
    workspaceSlug: string;
    errors: Record<string, string>;
    onClose: () => void;
}) {
    const { t } = useTranslate();

    /*
     * Seeded from the row and nothing else. The caller mounts this fresh per
     * stretch — see the key on it — so the fields start out as the row reads
     * rather than being copied into state by an effect afterwards, which is
     * both a render too late and a form that would remember the last
     * correction when the next row is opened.
     */
    const [date, setDate] = useState(entry.date);
    const [startedAt, setStartedAt] = useState(entry.startedTime);
    const [endedAt, setEndedAt] = useState(entry.endedTime ?? '');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('timeclock.edit_title')}</DialogTitle>
                    <DialogDescription>
                        {t('timeclock.edit_explanation')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="entry-date">
                            {t('timeclock.date')}
                        </Label>
                        <Input
                            id="entry-date"
                            type="date"
                            value={date}
                            onChange={(event) => setDate(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="entry-started">
                            {t('timeclock.started_at')}
                        </Label>
                        <Input
                            id="entry-started"
                            type="time"
                            value={startedAt}
                            onChange={(event) =>
                                setStartedAt(event.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="entry-ended">
                            {t('timeclock.ended_at')}
                        </Label>
                        <Input
                            id="entry-ended"
                            type="time"
                            value={endedAt}
                            // A running shift has no end yet, and the field
                            // stays empty rather than being filled with now:
                            // clocking out is the button, not this form.
                            onChange={(event) => setEndedAt(event.target.value)}
                        />
                    </div>
                </div>

                <InputError message={errors.startedAt ?? errors.endedAt} />

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('timeclock.cancel')}
                    </Button>
                    <Button
                        onClick={() => {
                            if (entry === null) {
                                return;
                            }

                            router.patch(
                                update.url({
                                    workspace: workspaceSlug,
                                    timeEntry: entry.id,
                                }),
                                {
                                    date,
                                    startedAt,
                                    endedAt: endedAt === '' ? null : endedAt,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                },
                            );
                        }}
                    >
                        {t('timeclock.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
