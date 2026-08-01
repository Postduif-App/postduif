import { useState, useSyncExternalStore } from 'react';

import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

/** The browser's own zone. A stable string, so React can compare snapshots. */
function readBrowserTimezone(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/** There is no browser on the server, and no zone to suggest. */
function readNothing(): null {
    return null;
}

function subscribeToNothing(): () => void {
    return () => {};
}

/**
 * The zone repeating times are read in.
 *
 * A plain select rather than a searchable combobox: this is chosen once and
 * then forgotten, and grouping by region gets somebody to their own city
 * quickly enough that a search field would be machinery for a one-off.
 */
export function TimezoneField({
    timezones,
    value,
    error,
}: {
    timezones: string[];
    value: string;
    error?: string;
}) {
    const [selected, setSelected] = useState(value);

    /*
     * What the browser thinks. Offered rather than applied: somebody on a
     * laptop in another country for a week has not moved, and a setting that
     * quietly followed them there would rewrite their working hours.
     *
     * Read through an external store rather than during render, because the
     * server has no browser to ask: reading it straight would have the server
     * render UTC and the browser render Amsterdam, which is a hydration
     * mismatch — the same shape of bug as the composer draft. Nothing ever
     * changes it, so the subscribe half has nothing to do.
     */
    const detected = useSyncExternalStore(
        subscribeToNothing,
        readBrowserTimezone,
        readNothing,
    );

    const regions = Object.entries(
        timezones.reduce<Record<string, string[]>>((groups, zone) => {
            const region = zone.split('/')[0];

            (groups[region] ??= []).push(zone);

            return groups;
        }, {}),
    );

    return (
        <div className="grid gap-2">
            <Label htmlFor="timezone">Tijdzone</Label>

            <select
                id="timezone"
                name="timezone"
                value={selected}
                onChange={(event) => setSelected(event.target.value)}
                className="mt-1 block w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
            >
                {regions.map(([region, zones]) => (
                    <optgroup key={region} label={region}>
                        {zones.map((zone) => (
                            <option key={zone} value={zone}>
                                {zone.replace(/_/g, ' ')}
                            </option>
                        ))}
                    </optgroup>
                ))}
            </select>

            <p className="text-xs text-muted-foreground">
                Waarin herhalende tijden gelezen worden, zoals een status die
                elke werkdag om negen uur ingaat.
                {detected !== null &&
                    timezones.includes(detected) &&
                    detected !== selected && (
                        <>
                            {' '}
                            Je browser staat op {detected.replace(
                                /_/g,
                                ' ',
                            )}.{' '}
                            <button
                                type="button"
                                onClick={() => setSelected(detected)}
                                className="underline underline-offset-4"
                            >
                                Overnemen
                            </button>
                        </>
                    )}
            </p>

            <InputError className="mt-2" message={error} />
        </div>
    );
}
