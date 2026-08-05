import { Form, Head } from '@inertiajs/react';
import { Palette, Type } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { update } from '@/routes/workspace/theme';

interface AccentOption {
    value: string;
    label: string;
    color: string;
    /** The text colour that belongs on top of it — amber wants dark, rose light. */
    foreground: string;
}

interface FontOption {
    value: string;
    label: string;
    stack: string;
}

interface WorkspaceThemeProps {
    workspace: {
        name: string;
        accent: string;
        font: string;
    };
    accentOptions: AccentOption[];
    fontOptions: FontOption[];
}

export default function WorkspaceThemeSettings({
    workspace,
    accentOptions,
    fontOptions,
}: WorkspaceThemeProps) {
    // Controlled, unlike the radio lists elsewhere in settings: the swatch hides
    // its radio entirely and the font list renders each option in its own
    // letter, so "which one is chosen" has to be readable state rather than
    // something only the DOM knows.
    const [accent, setAccent] = useState(workspace.accent);
    const [font, setFont] = useState(workspace.font);
    const { t } = useTranslate();

    const chosenFont =
        fontOptions.find((option) => option.value === font)?.stack ?? undefined;
    const chosenAccent = accentOptions.find(
        (option) => option.value === accent,
    );

    return (
        <>
            <Head title={t('settings.theme.head')} />

            <SettingsSection
                title={t('settings.theme.title')}
                description={t('settings.theme.description', {
                    workspace: workspace.name,
                })}
            >
                <Form {...update.form()} options={{ preserveScroll: true }}>
                    {/*
                        Rules between the groups, as on the permissions screen —
                        and on a wrapping div for the same reason: a legend cuts
                        a hole in its own fieldset's border.
                    */}
                    {({ processing, errors, recentlySuccessful }) => (
                        <div className="space-y-8 [&>div+div]:border-t [&>div+div]:border-border/60 [&>div+div]:pt-8">
                            <div>
                                <fieldset className="grid gap-3">
                                    <legend className="flex items-center gap-1.5 text-sm font-medium">
                                        <Palette className="size-4 text-muted-foreground" />
                                        {t('settings.theme.accent')}
                                    </legend>
                                    <p className="text-sm text-muted-foreground">
                                        {t('settings.theme.accent_hint')}
                                    </p>

                                    <div className="flex flex-wrap gap-2">
                                        {accentOptions.map((option) => (
                                            <label
                                                key={option.value}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                                    accent === option.value
                                                        ? 'border-foreground/40 bg-muted'
                                                        : 'hover:bg-muted/50',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="accent"
                                                    value={option.value}
                                                    checked={
                                                        accent === option.value
                                                    }
                                                    onChange={() =>
                                                        setAccent(option.value)
                                                    }
                                                    className="sr-only"
                                                />
                                                <span
                                                    aria-hidden
                                                    className="size-4 rounded-full border border-black/10"
                                                    style={{
                                                        backgroundColor:
                                                            option.color,
                                                    }}
                                                />
                                                {option.label}
                                            </label>
                                        ))}
                                    </div>
                                    <InputError message={errors.accent} />
                                </fieldset>
                            </div>

                            <div>
                                <fieldset className="grid gap-3">
                                    <legend className="flex items-center gap-1.5 text-sm font-medium">
                                        <Type className="size-4 text-muted-foreground" />
                                        {t('settings.theme.font')}
                                    </legend>
                                    <p className="text-sm text-muted-foreground">
                                        {t('settings.theme.font_hint')}
                                    </p>

                                    {fontOptions.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex max-w-sm cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors',
                                                font === option.value
                                                    ? 'border-primary bg-primary/5'
                                                    : 'hover:bg-muted/50',
                                            )}
                                            style={{ fontFamily: option.stack }}
                                        >
                                            <input
                                                type="radio"
                                                name="font"
                                                value={option.value}
                                                checked={font === option.value}
                                                onChange={() =>
                                                    setFont(option.value)
                                                }
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                    <InputError message={errors.font} />
                                </fieldset>
                            </div>

                            {/*
                                The two choices together, before saving commits
                                them to everybody: a colour and a letter each
                                look fine on their own and can still be an odd
                                pair.
                            */}
                            {/*
                                Wrapped, so that the rule and its padding land
                                on the wrapper rather than inside the card —
                                otherwise the preview grows a top margin its own
                                three lines do not account for.
                            */}
                            <div>
                                <div
                                    className="max-w-sm rounded-lg border bg-muted/30 p-4"
                                    style={{ fontFamily: chosenFont }}
                                >
                                    <p className="text-sm font-medium">
                                        {t('settings.theme.preview')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {t('settings.theme.preview_hint', {
                                            workspace: workspace.name,
                                        })}
                                    </p>
                                    <span
                                        className="mt-3 inline-flex rounded-md px-3 py-1.5 text-sm font-medium"
                                        style={{
                                            backgroundColor:
                                                chosenAccent?.color,
                                            color: chosenAccent?.foreground,
                                        }}
                                    >
                                        {t('settings.theme.preview_button')}
                                    </span>
                                </div>
                            </div>

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
