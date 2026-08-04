import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';

import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { update } from '@/routes/notifications';

interface Threshold {
    value: number;
    label: string;
}

interface NotificationsProps {
    preferences: {
        notifyAfterMinutes: number | null;
        viaMail: boolean;
        viaPushover: boolean;
        /** The key itself never leaves the server; this is all the form knows. */
        hasPushoverKey: boolean;
    };
    thresholds: Threshold[];
    pushoverAvailable: boolean;
}

/** The value the select uses for "never" — a select cannot hold null. */
const OFF = 'off';

export default function Notifications({
    preferences,
    thresholds,
    pushoverAvailable,
}: NotificationsProps) {
    const [after, setAfter] = useState(
        preferences.notifyAfterMinutes === null
            ? OFF
            : String(preferences.notifyAfterMinutes),
    );
    const [viaMail, setViaMail] = useState(preferences.viaMail);
    const [viaPushover, setViaPushover] = useState(preferences.viaPushover);
    const [editingKey, setEditingKey] = useState(!preferences.hasPushoverKey);
    const { t } = useTranslate();

    const on = after !== OFF;

    return (
        <>
            <Head title={t('settings.notifications.title')} />

            <h1 className="sr-only">{t('settings.notifications.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.notifications.title')}
                    description={t('settings.notifications.description')}
                />

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

                            <fieldset
                                className="grid gap-3"
                                disabled={!on}
                                aria-disabled={!on}
                            >
                                <legend className="text-sm font-medium">
                                    {t('settings.notifications.where')}
                                </legend>

                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={viaMail}
                                        onChange={(event) =>
                                            setViaMail(event.target.checked)
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {t('settings.notifications.mail')}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {t(
                                                'settings.notifications.mail_hint',
                                            )}
                                        </span>
                                    </span>
                                </label>

                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={viaPushover}
                                        disabled={!pushoverAvailable}
                                        onChange={(event) =>
                                            setViaPushover(event.target.checked)
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {t(
                                                'settings.notifications.pushover',
                                            )}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {pushoverAvailable
                                                ? t(
                                                      'settings.notifications.pushover_hint',
                                                  )
                                                : t(
                                                      'settings.notifications.pushover_missing',
                                                  )}
                                        </span>
                                    </span>
                                </label>
                                {viaPushover && pushoverAvailable && (
                                    <div className="grid gap-2 pl-7">
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

                            <div className="flex justify-start">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('settings.actions.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
