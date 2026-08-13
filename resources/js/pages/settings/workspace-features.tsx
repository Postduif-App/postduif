import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { update } from '@/routes/workspace/features';

interface FeatureItem {
    key: string;
    label: string;
    description: string;
    group: string;
    enabled: boolean;
    /** Whether a fresh workspace would have had it switched on. */
    onByDefault: boolean;
}

interface FeatureGroup {
    value: string;
    label: string;
    description: string;
}

interface WorkspaceFeaturesProps {
    workspace: { name: string };
    features: FeatureItem[];
    groups: FeatureGroup[];
}

/**
 * A part of the product, drawn as a card you tick.
 *
 * Wider than the toggle rows on the rights page — those are one line each,
 * these carry the sentence saying what switching it off costs, and that
 * sentence is the whole reason these are classes rather than closures.
 */
const FEATURE_ROW =
    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/50';

/**
 * Which parts of the product this workspace offers.
 *
 * The features are grouped rather than listed: eighteen equally-weighted cards
 * in one column is a list nobody finishes reading, and the grouping is the
 * feature class's own answer — see WorkspaceFeature::group(), which is abstract
 * so that a new one cannot quietly fall outside the list.
 *
 * Everything is checked in the browser and again on the server; this page only
 * decides what the form says.
 */
export default function WorkspaceFeatures({
    workspace,
    features,
    groups,
}: WorkspaceFeaturesProps) {
    const { t } = useTranslate();

    /*
     * Held here rather than left to the checkboxes themselves, so the count
     * under the heading can say how many are on without reading the DOM back.
     */
    const [enabled, setEnabled] = useState<string[]>(
        features.filter((feature) => feature.enabled).map((f) => f.key),
    );

    const toggle = (key: string, on: boolean) =>
        setEnabled((current) =>
            on ? [...current, key] : current.filter((each) => each !== key),
        );

    return (
        <>
            <Head title={t('settings.features.head')} />

            <SettingsSection
                title={t('settings.features.title')}
                description={t('settings.features.description', {
                    workspace: workspace.name,
                })}
            >
                <p className="max-w-prose rounded-lg border border-border/60 bg-muted/40 p-3 text-sm text-muted-foreground">
                    {t('settings.features.warning')}
                </p>

                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-8">
                            {/*
                                The empty entry that makes "nothing is on" a
                                thing this form can say: an unticked checkbox
                                sends nothing at all, so a page with none ticked
                                would send no list rather than an empty one.
                            */}
                            <input type="hidden" name="features[]" value="" />

                            {groups.map((group) => {
                                const inGroup = features.filter(
                                    (feature) => feature.group === group.value,
                                );

                                // A group the application has no features in
                                // yet: a heading over nothing reads as a bug.
                                if (inGroup.length === 0) {
                                    return null;
                                }

                                return (
                                    <fieldset
                                        key={group.value}
                                        className="grid gap-3 border-t border-border/60 pt-8 first:border-0 first:pt-0"
                                    >
                                        <legend className="text-sm font-medium">
                                            {group.label}
                                        </legend>
                                        <p className="max-w-prose text-sm text-muted-foreground">
                                            {group.description}
                                        </p>

                                        {inGroup.map((feature) => (
                                            <label
                                                key={feature.key}
                                                className={FEATURE_ROW}
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="features[]"
                                                    value={feature.key}
                                                    className="mt-1"
                                                    checked={enabled.includes(
                                                        feature.key,
                                                    )}
                                                    onChange={(event) =>
                                                        toggle(
                                                            feature.key,
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                <span className="grid gap-1">
                                                    <span className="font-medium">
                                                        {feature.label}
                                                        {!feature.onByDefault && (
                                                            <span className="ml-2 rounded-full border px-1.5 py-0.5 text-[0.65rem] font-normal tracking-wide text-muted-foreground uppercase">
                                                                {t(
                                                                    'settings.features.off_by_default',
                                                                )}
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {feature.description}
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                    </fieldset>
                                );
                            })}

                            <InputError message={errors.features} />

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('settings.actions.save')}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-muted-foreground">
                                        {t('settings.actions.saved')}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Form>
            </SettingsSection>
        </>
    );
}
