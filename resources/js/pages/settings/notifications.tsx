import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';

import { ChoiceText } from '@/components/choice-text';
import InputError from '@/components/input-error';
import type { PushDevice } from '@/components/push-devices';
import { PushDevices } from '@/components/push-devices';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { update } from '@/routes/notifications';

/**
 * A choice you tick, drawn as a card.
 *
 * The same bordered row the invite dialog and the workflow form use for picking
 * something, so that "where should this reach me" looks like every other choice
 * in the application rather than a bare checkbox beside two lines of grey text.
 */
const CHOICE_ROW =
    'flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition-colors hover:bg-accent/40 has-[:disabled]:cursor-not-allowed has-[:disabled]:hover:bg-transparent';

interface Threshold {
    value: number;
    label: string;
}

interface NotificationsProps {
    preferences: {
        notifyAfterMinutes: number | null;
        viaMail: boolean;
        viaPushover: boolean;
        viaPush: boolean;
        /** The key itself never leaves the server; this is all the form knows. */
        hasPushoverKey: boolean;
    };
    thresholds: Threshold[];
    devices: PushDevice[];
    pushoverAvailable: boolean;
    /** Whether this installation has a VAPID pair to sign pushes with at all. */
    pushAvailable: boolean;
}

/** The value the select uses for "never" — a select cannot hold null. */
const OFF = 'off';

export default function Notifications({
    preferences,
    thresholds,
    devices,
    pushoverAvailable,
    pushAvailable,
}: NotificationsProps) {
    const [after, setAfter] = useState(
        preferences.notifyAfterMinutes === null
            ? OFF
            : String(preferences.notifyAfterMinutes),
    );
    const [viaMail, setViaMail] = useState(preferences.viaMail);
    const [viaPushover, setViaPushover] = useState(preferences.viaPushover);
    const [viaPush, setViaPush] = useState(preferences.viaPush);
    const [editingKey, setEditingKey] = useState(!preferences.hasPushoverKey);
    const { t } = useTranslate();

    const on = after !== OFF;

    return (
        <>
            <Head title={t('settings.notifications.title')} />

            <h1 className="sr-only">{t('settings.notifications.title')}</h1>

            <SettingsSection
                title={t('settings.notifications.title')}
                description={t('settings.notifications.description')}
            >
                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="notify-after">
                                    {t('settings.notifications.after')}
                                </Label>
                                <Select
                                    value={after}
                                    onValueChange={setAfter}
                                    name="notify_after_minutes_choice"
                                >
                                    <SelectTrigger
                                        id="notify-after"
                                        className="w-64"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={OFF}>
                                            {t('settings.notifications.never')}
                                        </SelectItem>
                                        {thresholds.map((threshold) => (
                                            <SelectItem
                                                key={threshold.value}
                                                value={String(threshold.value)}
                                            >
                                                {t(
                                                    'settings.notifications.longer_than',
                                                    {
                                                        duration:
                                                            threshold.label,
                                                    },
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {/* The select is not a form control the server
                                    sees; this is. "Nooit" sends nothing, which
                                    the server reads as switched off. */}
                                {on && (
                                    <input
                                        type="hidden"
                                        name="notify_after_minutes"
                                        value={after}
                                    />
                                )}
                                <p className="text-xs text-muted-foreground">
                                    {t('settings.notifications.summary_hint')}
                                </p>
                                <InputError
                                    message={errors.notify_after_minutes}
                                />
                            </div>

                            {/*
                                Dimmed as a whole while nothing is being sent,
                                rather than each row dimming itself: with the
                                threshold on "Nooit" these choices have no
                                effect, and saying that once is quieter than
                                three greyed-out lines that look broken.
                            */}
                            <fieldset
                                className={cn(
                                    'grid gap-3 transition-opacity',
                                    !on && 'opacity-60',
                                )}
                                disabled={!on}
                                aria-disabled={!on}
                            >
                                <legend className="mb-1 text-sm font-medium">
                                    {t('settings.notifications.where')}
                                </legend>

                                <label className={CHOICE_ROW}>
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={viaMail}
                                        onChange={(event) =>
                                            setViaMail(event.target.checked)
                                        }
                                    />
                                    <ChoiceText
                                        title={t('settings.notifications.mail')}
                                        hint={t(
                                            'settings.notifications.mail_hint',
                                        )}
                                    />
                                </label>

                                <label className={CHOICE_ROW}>
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={viaPush}
                                        disabled={!pushAvailable}
                                        onChange={(event) =>
                                            setViaPush(event.target.checked)
                                        }
                                    />
                                    <ChoiceText
                                        title={t('settings.notifications.push')}
                                        hint={
                                            pushAvailable
                                                ? t(
                                                      'settings.notifications.push_hint',
                                                  )
                                                : t(
                                                      'settings.notifications.push_missing',
                                                  )
                                        }
                                    />
                                </label>

                                {/*
                                    The permission and the devices sit under the
                                    choice, the way the Pushover key does: this
                                    is the browser's own answer rather than a
                                    preference, and it has nothing to say to
                                    somebody who does not want browser
                                    notifications at all.
                                */}
                                {viaPush && pushAvailable && (
                                    <PushDevices devices={devices} />
                                )}

                                <label className={CHOICE_ROW}>
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={viaPushover}
                                        disabled={!pushoverAvailable}
                                        onChange={(event) =>
                                            setViaPushover(event.target.checked)
                                        }
                                    />
                                    <ChoiceText
                                        title={t(
                                            'settings.notifications.pushover',
                                        )}
                                        hint={
                                            pushoverAvailable
                                                ? t(
                                                      'settings.notifications.pushover_hint',
                                                  )
                                                : t(
                                                      'settings.notifications.pushover_missing',
                                                  )
                                        }
                                    />
                                </label>

                                {/*
                                    Indented under the choice it belongs to, and
                                    only once that choice is made: the key is
                                    Pushover's business and has nothing to say
                                    to somebody who picked mail.
                                */}
                                {viaPushover && pushoverAvailable && (
                                    <div className="grid gap-2 border-l-2 border-border py-1 pl-4">
                                        <Label htmlFor="pushover-key">
                                            {t(
                                                'settings.notifications.pushover_key',
                                            )}
                                        </Label>
                                        {editingKey ? (
                                            <Input
                                                id="pushover-key"
                                                name="pushover_user_key"
                                                autoComplete="off"
                                                placeholder="uQiRzpo4DXghDmr9QzzfQu27cmVRsG"
                                            />
                                        ) : (
                                            <div className="flex items-center gap-3">
                                                <p className="text-sm text-muted-foreground">
                                                    {t(
                                                        'settings.notifications.pushover_key_set',
                                                    )}
                                                </p>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditingKey(true)
                                                    }
                                                >
                                                    {t(
                                                        'settings.notifications.pushover_key_replace',
                                                    )}
                                                </Button>
                                            </div>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'settings.notifications.pushover_key_hint',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.pushover_user_key}
                                        />
                                    </div>
                                )}
                            </fieldset>

                            {/* Outside the fieldset on purpose: a disabled one
                                submits none of its fields, so switching to
                                "Nooit" would forget which delivery methods you
                                had picked the moment you switched back on. */}
                            <input
                                type="hidden"
                                name="via_mail"
                                value={viaMail ? 1 : 0}
                            />
                            <input
                                type="hidden"
                                name="via_pushover"
                                value={viaPushover ? 1 : 0}
                            />
                            <input
                                type="hidden"
                                name="via_push"
                                value={viaPush ? 1 : 0}
                            />

                            <div className="flex justify-start pt-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('settings.actions.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>
        </>
    );
}
