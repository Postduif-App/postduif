import { router } from '@inertiajs/react';
import { BellRing, MonitorSmartphone, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePushNotifications } from '@/hooks/use-push-notifications';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';

/** One browser the server knows how to reach, as the settings screen sees it. */
export interface PushDevice {
    /** The push service's handle for this browser, and the only key the delete route takes. */
    endpoint: string;
    /** "Chrome op macOS", or the raw user agent when it could not be placed. */
    name: string;
    /** Relative, already worded by the server. Null until something has been sent. */
    lastUsedAt: string | null;
}

/**
 * Where the subscription endpoints are withdrawn.
 *
 * Spelled out rather than taken from the generated routes because the delete
 * takes a JSON body from `fetch()` rather than an Inertia visit — the same
 * reason lib/push.ts holds its own copy.
 */
const SUBSCRIPTION_URL = '/app/settings/notifications/push';
const TEST_URL = '/app/settings/notifications/push/test';

/** What the server saw when it tried, or how the trying itself failed. */
type TestResult =
    | { kind: 'delivered'; count: number }
    | { kind: 'refused' }
    | { kind: 'nobody' }
    | { kind: 'error' };

/**
 * Turning browser notifications on for the device you are looking at, and the
 * list of every device already switched on.
 *
 * The checkbox above this one is a preference the server stores; this is the
 * browser's own answer, which no server can set. They are separate on purpose:
 * wanting notifications is not the same as this particular laptop having been
 * asked, and somebody who ticks the box on their phone should not find their
 * desktop silently subscribed.
 *
 * All five states of the hook get an answer here. Two of them are prose rather
 * than a button: `unsupported`, where there is nothing to press, and `denied`,
 * which JavaScript cannot undo — offering "Meldingen toestaan" there would open
 * no prompt and change nothing, which reads as a broken button rather than as
 * the browser holding its ground.
 */
export function PushDevices({ devices }: { devices: PushDevice[] }) {
    const { t } = useTranslate();
    const { status, isLoading, isBusy, failure, subscribe, unsubscribe } =
        usePushNotifications();
    const [isTesting, setTesting] = useState(false);
    const [result, setResult] = useState<TestResult | null>(null);

    /*
     * The list lives on the server, so every change to it has to be re-read.
     * A partial reload rather than a full one: the form around this is holding
     * unsaved checkbox state, and there is no reason to disturb it.
     */
    const refresh = () => router.reload({ only: ['devices'] });

    const handleSubscribe = async () => {
        await subscribe();
        refresh();
    };

    const handleUnsubscribe = async () => {
        await unsubscribe();
        refresh();
    };

    /*
     * Delivered is not the same as arrived. The count the server gives back is
     * what the push services accepted, which is as far as our side can see —
     * the bubble itself is drawn by the browser afterwards and nobody reports
     * back on it. So a delivered count is worded as "on its way" rather than as
     * a promise, and it is precisely the case where the count is zero that is
     * worth saying out loud: that is a refusal we can point at.
     */
    const runTest = async () => {
        setTesting(true);
        setResult(null);

        try {
            const response = await fetch(TEST_URL, {
                method: 'POST',
                headers: mutatingHeaders(),
            });

            if (!response.ok) {
                setResult({ kind: 'error' });

                return;
            }

            const { sent, delivered } = (await response.json()) as {
                sent: number;
                delivered: number;
            };

            setResult(
                sent === 0
                    ? { kind: 'nobody' }
                    : delivered === 0
                      ? { kind: 'refused' }
                      : { kind: 'delivered', count: delivered },
            );
        } catch {
            setResult({ kind: 'error' });
        } finally {
            setTesting(false);
            // last_used_at has moved for every device that was reached.
            refresh();
        }
    };

    const forget = async (endpoint: string) => {
        await fetch(SUBSCRIPTION_URL, {
            method: 'DELETE',
            headers: mutatingHeaders(),
            body: JSON.stringify({ endpoint }),
        });

        refresh();
    };

    return (
        <div className="grid gap-3 border-l-2 border-border py-1 pl-4">
            {isLoading ? (
                <p className="text-sm text-muted-foreground">
                    {t('settings.notifications.push_checking')}
                </p>
            ) : (
                <ThisBrowser
                    status={status}
                    isBusy={isBusy}
                    onSubscribe={handleSubscribe}
                    onUnsubscribe={handleUnsubscribe}
                />
            )}

            {failure && (
                <p className="text-sm text-destructive">
                    {t(
                        failure === 'no-key'
                            ? 'settings.notifications.push_no_key'
                            : failure === 'denied'
                              ? 'settings.notifications.push_denied'
                              : failure === 'unsupported'
                                ? 'settings.notifications.push_unsupported'
                                : 'settings.notifications.push_failed',
                    )}
                </p>
            )}

            <div className="grid gap-2">
                <p className="text-sm font-medium">
                    {t('settings.notifications.devices')}
                </p>

                {devices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('settings.notifications.devices_empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-border overflow-hidden rounded-md border">
                        {devices.map((device) => (
                            <li
                                key={device.endpoint}
                                className="flex items-center justify-between gap-3 p-3"
                            >
                                <span className="flex min-w-0 items-center gap-3">
                                    <MonitorSmartphone className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm">
                                            {device.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {device.lastUsedAt
                                                ? t(
                                                      'settings.notifications.device_last_used',
                                                      {
                                                          moment: device.lastUsedAt,
                                                      },
                                                  )
                                                : t(
                                                      'settings.notifications.device_never_used',
                                                  )}
                                        </span>
                                    </span>
                                </span>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() => forget(device.endpoint)}
                                >
                                    <Trash2 className="size-4" />
                                    <span className="sr-only">
                                        {t(
                                            'settings.notifications.device_remove',
                                        )}
                                    </span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {devices.length > 0 && (
                <div className="grid gap-1">
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={isTesting}
                            onClick={runTest}
                        >
                            {isTesting ? (
                                <Spinner />
                            ) : (
                                <BellRing className="size-4" />
                            )}
                            {t('settings.notifications.test_send')}
                        </Button>
                    </div>

                    {result && <TestOutcome result={result} />}
                </div>
            )}
        </div>
    );
}

/**
 * What came of pressing test.
 *
 * The refusal case gets the destructive colour and the others do not: a zero
 * delivered count is the only one of these that means something is broken, and
 * it is the answer somebody pressing this button is actually hunting for.
 */
function TestOutcome({ result }: { result: TestResult }) {
    const { t, tChoice } = useTranslate();

    if (result.kind === 'delivered') {
        return (
            <p className="text-xs text-muted-foreground">
                {tChoice(
                    'settings.notifications.test_delivered',
                    result.count,
                    { count: result.count },
                )}
            </p>
        );
    }

    return (
        <p
            className={
                result.kind === 'refused'
                    ? 'text-xs text-destructive'
                    : 'text-xs text-muted-foreground'
            }
        >
            {t(
                result.kind === 'refused'
                    ? 'settings.notifications.test_refused'
                    : result.kind === 'nobody'
                      ? 'settings.notifications.test_nobody'
                      : 'settings.notifications.test_error',
            )}
        </p>
    );
}

/** What this particular browser has to say, and what can be done about it. */
function ThisBrowser({
    status,
    isBusy,
    onSubscribe,
    onUnsubscribe,
}: {
    status: ReturnType<typeof usePushNotifications>['status'];
    isBusy: boolean;
    onSubscribe: () => void;
    onUnsubscribe: () => void;
}) {
    const { t } = useTranslate();

    if (status === 'unsupported') {
        return (
            <p className="text-sm text-muted-foreground">
                {t('settings.notifications.push_unsupported')}
            </p>
        );
    }

    if (status === 'denied') {
        return (
            <p className="text-sm text-muted-foreground">
                {t('settings.notifications.push_denied')}
            </p>
        );
    }

    if (status === 'subscribed') {
        return (
            <div className="flex flex-wrap items-center gap-3">
                <p className="text-sm text-muted-foreground">
                    {t('settings.notifications.push_on')}
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={isBusy}
                    onClick={onUnsubscribe}
                >
                    {isBusy && <Spinner />}
                    {t('settings.notifications.push_off')}
                </Button>
            </div>
        );
    }

    /*
     * 'default' and 'granted' both land here. Granted-but-not-subscribed is the
     * device that was switched off again: the browser never takes permission
     * back, so the same button simply subscribes without a prompt this time.
     */
    return (
        <div className="grid gap-1">
            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    disabled={isBusy}
                    onClick={onSubscribe}
                >
                    {isBusy && <Spinner />}
                    {t('settings.notifications.push_allow')}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                {t('settings.notifications.push_allow_hint')}
            </p>
        </div>
    );
}
