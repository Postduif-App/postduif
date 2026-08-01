import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CalendarClock, Plus, X } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { destroy, reorder, store, update } from '@/routes/status-rules';
import type { Availability } from '@/types/auth';

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

const DAYS: { iso: number; short: string }[] = [
    { iso: 1, short: 'ma' },
    { iso: 2, short: 'di' },
    { iso: 3, short: 'wo' },
    { iso: 4, short: 'do' },
    { iso: 5, short: 'vr' },
    { iso: 6, short: 'za' },
    { iso: 7, short: 'zo' },
];

const AVAILABILITIES: { value: Availability; label: string }[] = [
    { value: 'available', label: 'Beschikbaar' },
    { value: 'away', label: 'Afwezig' },
    { value: 'do-not-disturb', label: 'Niet storen' },
];

/** How a rule's days and hours read as a sentence. */
function describe(rule: StatusRule): string {
    const days =
        rule.days.length === 0
            ? 'Elke dag'
            : DAYS.filter((day) => rule.days.includes(day.iso))
                  .map((day) => day.short)
                  .join(', ');

    if (rule.startsAt === null || rule.endsAt === null) {
        return `${days}, de hele dag`;
    }

    /*
     * A window whose end is before its start runs through midnight, and saying
     * so beats leaving somebody to work out why "22:00 - 06:00" is not empty.
     */
    const overnight =
        rule.endsAt <= rule.startsAt ? ' (tot de volgende ochtend)' : '';

    return `${days}, ${rule.startsAt} - ${rule.endsAt}${overnight}`;
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
                <Label>Dagen</Label>

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
                            {day.short}
                        </button>
                    ))}
                </div>

                <p className="text-xs text-muted-foreground">
                    Geen dag aangevinkt betekent elke dag — zo schrijf je de
                    regel die eronder alles opvangt.
                </p>
            </div>

            <div className="grid gap-2">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={allDay}
                        onChange={(event) => setAllDay(event.target.checked)}
                    />
                    De hele dag
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
                            aria-label="Van"
                        />
                        <span className="text-muted-foreground">tot</span>
                        <Input
                            type="time"
                            value={endsAt}
                            onChange={(event) => setEndsAt(event.target.value)}
                            className="w-32"
                            aria-label="Tot"
                        />
                    </div>
                )}

                {!allDay && endsAt <= startsAt && (
                    <p className="text-xs text-muted-foreground">
                        Deze regel loopt door tot de volgende ochtend.
                    </p>
                )}
            </div>

            <div className="grid gap-2 sm:grid-cols-[5rem_1fr]">
                <div className="grid gap-2">
                    <Label htmlFor="rule-emoji">Emoji</Label>
                    <Input
                        id="rule-emoji"
                        value={emoji}
                        onChange={(event) => setEmoji(event.target.value)}
                        placeholder="💼"
                    />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="rule-text">Status</Label>
                    <Input
                        id="rule-text"
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        placeholder="Aan het werk"
                    />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="rule-availability">Bereikbaarheid</Label>

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
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex gap-2">
                <Button onClick={submit}>Opslaan</Button>
                <Button variant="ghost" onClick={onDone}>
                    Annuleren
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
            <Head title="Statusregels" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Statusregels"
                    description={`Je status volgens de klok in ${timezone}. De bovenste regel die past, wint.`}
                />

                {rules.length === 0 && !adding && (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <CalendarClock className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            Nog geen regels
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Bijvoorbeeld: elke werkdag van 9 tot 17 &ldquo;aan
                            het werk&rdquo;, en daaronder een regel zonder dagen
                            of tijden voor al het andere.
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
                                        aria-label="Naar boven"
                                        className="text-muted-foreground disabled:opacity-30"
                                    >
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => move(index, 1)}
                                        disabled={index === rules.length - 1}
                                        aria-label="Naar beneden"
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
                                        {rule.statusText ?? 'Geen status'}
                                        {rule.id === activeRuleId && (
                                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                                nu
                                            </span>
                                        )}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {describe(rule)}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(destroy.url(rule.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                    aria-label="Regel verwijderen"
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
                        Regel toevoegen
                    </Button>
                )}
            </div>
        </>
    );
}
