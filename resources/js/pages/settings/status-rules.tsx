import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CalendarClock, Plus, X } from 'lucide-react';
import { useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, reorder, store, update } from '@/routes/status-rules';
import type { Availability } from '@/types/auth';
import type { TranslationKey } from '@/types/translations';

type Translate = ReturnType<typeof useTranslate>['t'];

interface StatusRule {
    id: number;
    /** ISO weekdays, 1 = Monday. Empty means every day. */
    days: number[];
    startsAt: string | null;
    endsAt: string | null;
    statusEmoji: string | null;
    statusText: string | null;
    availability: Availability;
}

interface StatusRulesProps {
    rules: StatusRule[];
    /** The zone these times are read in — set on the profile screen. */
    timezone: string;
    /** Which rule is winning right now, or null when none applies. */
    activeRuleId: number | null;
}

/*
 * The weekdays and the three availabilities as plain constants holding keys
 * rather than words: a constant lives outside the component and cannot call a
 * hook, so the wording is looked up where it is rendered.
 *
 * The availabilities point at the enum's own lines, which is where every other
 * screen reads them from — a second wording here is the one that would go stale
 * when a case is renamed.
 */
const DAYS = [
    { iso: 1, key: 'settings.status_rules.day.monday' },
    { iso: 2, key: 'settings.status_rules.day.tuesday' },
    { iso: 3, key: 'settings.status_rules.day.wednesday' },
    { iso: 4, key: 'settings.status_rules.day.thursday' },
    { iso: 5, key: 'settings.status_rules.day.friday' },
    { iso: 6, key: 'settings.status_rules.day.saturday' },
    { iso: 7, key: 'settings.status_rules.day.sunday' },
] as const;

const AVAILABILITIES = [
    { value: 'available', key: 'enums.availability.label.Available' },
    { value: 'away', key: 'enums.availability.label.Away' },
    { value: 'do-not-disturb', key: 'enums.availability.label.DoNotDisturb' },
] as const satisfies { value: Availability; key: TranslationKey }[];

/** How a rule's days and hours read as a sentence. */
function describe(rule: StatusRule, t: Translate): string {
    const days =
        rule.days.length === 0
            ? t('settings.status_rules.every_day')
            : DAYS.filter((day) => rule.days.includes(day.iso))
                  .map((day) => t(day.key))
                  .join(', ');

    if (rule.startsAt === null || rule.endsAt === null) {
        return t('settings.status_rules.summary_all_day', { days });
    }

    /*
     * A window whose end is before its start runs through midnight, and saying
     * so beats leaving somebody to work out why "22:00 - 06:00" is not empty.
     */
    return t(
        rule.endsAt <= rule.startsAt
            ? 'settings.status_rules.summary_overnight'
            : 'settings.status_rules.summary_window',
        { days, start: rule.startsAt, end: rule.endsAt },
    );
}

function RuleForm({
    rule,
    onDone,
}: {
    /** Null when this is the form for a new rule. */
    rule: StatusRule | null;
    onDone: () => void;
}) {
    const [days, setDays] = useState<number[]>(rule?.days ?? []);
    // An existing rule with no times set covers the whole day; a new one opens
    // on a window, because that is the thing people come here to write.
    const [allDay, setAllDay] = useState(
        rule !== null && rule.startsAt === null,
    );
    const [startsAt, setStartsAt] = useState(rule?.startsAt ?? '09:00');
    const [endsAt, setEndsAt] = useState(rule?.endsAt ?? '17:00');
    const [emoji, setEmoji] = useState(rule?.statusEmoji ?? '');
    const [text, setText] = useState(rule?.statusText ?? '');
    const [availability, setAvailability] = useState<Availability>(
        rule?.availability ?? 'available',
    );
    const { t } = useTranslate();

    const submit = () => {
        const payload = {
            days,
            starts_at: allDay ? null : startsAt,
            ends_at: allDay ? null : endsAt,
            status_emoji: emoji || null,
            status_text: text || null,
            availability,
        };

        const options = { preserveScroll: true, onSuccess: onDone };

        if (rule === null) {
            router.post(store.url(), payload, options);
        } else {
            router.patch(update.url(rule.id), payload, options);
        }
    };

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="grid gap-2">
                <Label>{t('settings.status_rules.days')}</Label>

                <div className="flex flex-wrap gap-1">
                    {DAYS.map((day) => (
                        <button
                            key={day.iso}
                            type="button"
                            onClick={() =>
                                setDays((current) =>
                                    current.includes(day.iso)
                                        ? current.filter(
                                              (iso) => iso !== day.iso,
                                          )
                                        : [...current, day.iso].sort(),
                                )
                            }
                            className={cn(
                                'rounded-md border px-3 py-1.5 text-sm capitalize transition-colors',
                                days.includes(day.iso)
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {t(day.key)}
                        </button>
                    ))}
                </div>

                <p className="text-xs text-muted-foreground">
                    {t('settings.status_rules.days_hint')}
                </p>
            </div>

            <div className="grid gap-2">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={allDay}
                        onChange={(event) => setAllDay(event.target.checked)}
                    />
                    {t('settings.status_rules.all_day')}
                </label>

                {!allDay && (
                    <div className="flex items-center gap-2">
                        <Input
                            type="time"
                            value={startsAt}
                            onChange={(event) =>
                                setStartsAt(event.target.value)
                            }
                            className="w-32"
                            aria-label={t('settings.status_rules.from')}
                        />
                        <span className="text-muted-foreground">
                            {t('settings.status_rules.to')}
                        </span>
                        <Input
                            type="time"
                            value={endsAt}
                            onChange={(event) => setEndsAt(event.target.value)}
                            className="w-32"
                            aria-label={t('settings.status_rules.until')}
                        />
                    </div>
                )}

                {!allDay && endsAt <= startsAt && (
                    <p className="text-xs text-muted-foreground">
                        {t('settings.status_rules.overnight_hint')}
                    </p>
                )}
            </div>

            <div className="grid gap-2 sm:grid-cols-[5rem_1fr]">
                <div className="grid gap-2">
                    <Label htmlFor="rule-emoji">
                        {t('settings.status_rules.emoji')}
                    </Label>
                    <Input
                        id="rule-emoji"
                        value={emoji}
                        onChange={(event) => setEmoji(event.target.value)}
                        placeholder="💼"
                    />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="rule-text">
                        {t('settings.status_rules.status')}
                    </Label>
                    <Input
                        id="rule-text"
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        placeholder={t(
                            'settings.status_rules.status_placeholder',
                        )}
                    />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="rule-availability">
                    {t('settings.status_rules.availability')}
                </Label>

                <select
                    id="rule-availability"
                    value={availability}
                    onChange={(event) =>
                        setAvailability(event.target.value as Availability)
                    }
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                >
                    {AVAILABILITIES.map((option) => (
                        <option key={option.value} value={option.value}>
                            {t(option.key)}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex gap-2">
                <Button onClick={submit}>{t('settings.actions.save')}</Button>
                <Button variant="ghost" onClick={onDone}>
                    {t('settings.actions.cancel')}
                </Button>
            </div>
        </div>
    );
}

export default function StatusRules({
    rules,
    timezone,
    activeRuleId,
}: StatusRulesProps) {
    const [editing, setEditing] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const { t } = useTranslate();

    /*
     * The whole order at once rather than a move-one endpoint: order is a
     * property of the list, and two quick clicks sent separately could arrive
     * the wrong way round.
     */
    const move = (index: number, direction: -1 | 1) => {
        const next = [...rules];
        const target = index + direction;

        if (target < 0 || target >= next.length) {
            return;
        }

        [next[index], next[target]] = [next[target], next[index]];

        router.put(
            reorder.url(),
            { ids: next.map((rule) => rule.id) },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={t('settings.status_rules.title')} />

            <SettingsSection
                title={t('settings.status_rules.title')}
                description={t('settings.status_rules.description', {
                    timezone,
                })}
            >
                {rules.length === 0 && !adding && (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <CalendarClock className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('settings.status_rules.empty')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('settings.status_rules.empty_hint')}
                        </p>
                    </div>
                )}

                <div className="space-y-2">
                    {rules.map((rule, index) =>
                        editing === rule.id ? (
                            <RuleForm
                                key={rule.id}
                                rule={rule}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div
                                key={rule.id}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg border p-3',
                                    rule.id === activeRuleId &&
                                        'border-primary bg-primary/5',
                                )}
                            >
                                <div className="flex flex-col">
                                    <button
                                        type="button"
                                        onClick={() => move(index, -1)}
                                        disabled={index === 0}
                                        aria-label={t(
                                            'settings.status_rules.move_up',
                                        )}
                                        className="text-muted-foreground disabled:opacity-30"
                                    >
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => move(index, 1)}
                                        disabled={index === rules.length - 1}
                                        aria-label={t(
                                            'settings.status_rules.move_down',
                                        )}
                                        className="text-muted-foreground disabled:opacity-30"
                                    >
                                        <ArrowDown className="size-4" />
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setEditing(rule.id)}
                                    className="min-w-0 flex-1 text-left"
                                >
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        {rule.statusEmoji && (
                                            <span>{rule.statusEmoji}</span>
                                        )}
                                        {rule.statusText ??
                                            t(
                                                'settings.status_rules.no_status',
                                            )}
                                        {rule.id === activeRuleId && (
                                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                                {t('settings.status_rules.now')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                                        {describe(rule, t)}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(destroy.url(rule.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                    aria-label={t(
                                        'settings.status_rules.delete',
                                    )}
                                    className="text-muted-foreground transition-colors hover:text-destructive"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>
                        ),
                    )}
                </div>

                {adding ? (
                    <RuleForm rule={null} onDone={() => setAdding(false)} />
                ) : (
                    <Button variant="outline" onClick={() => setAdding(true)}>
                        <Plus className="size-4" />
                        {t('settings.status_rules.add')}
                    </Button>
                )}
            </SettingsSection>
        </>
    );
}
