import { Form, Head, router } from '@inertiajs/react';
import { Check, Copy, Radio, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    destroy,
    rotate,
    store,
    toggle,
} from '@/routes/workspace/contract-webhooks';

interface ContractWebhook {
    id: number;
    name: string;
    url: string;
    events: string[];
    /** Readable again on purpose — see the controller. */
    secret: string;
    lastDeliveredAt: string | null;
    lastFailedAt: string | null;
    lastStatus: number | null;
    disabledAt: string | null;
}

interface ContractWebhooksProps {
    /** Every event there is, in the order the server wants them offered. */
    events: string[];
    webhooks: ContractWebhook[];
}

/**
 * A choice you tick, drawn as a card.
 *
 * The same bordered row the API tokens screen uses for its scopes, so that
 * "waarover wil je bericht" looks like every other choice in the application
 * rather than three bare checkboxes.
 */
const CHOICE_ROW =
    'flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition-colors hover:bg-accent/40';

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, copy] = useClipboard();

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => void copy(value)}
            aria-label={label}
            title={label}
        >
            {copied === value ? (
                <Check className="size-3.5 text-emerald-600" />
            ) : (
                <Copy className="size-3.5" />
            )}
        </Button>
    );
}

/**
 * Where this workspace's contract news goes.
 *
 * The other direction from the webhooks a channel has, and the screen says so
 * in as many words: there somebody posts to us, here we post to them. What that
 * changes for the person reading is what they have to do with the secret — it
 * is not a key they keep, it is one they have to carry across to the system at
 * the other end, or every message we send will be refused there.
 */
export default function ContractWebhooks({
    events,
    webhooks,
}: ContractWebhooksProps) {
    const [rotating, setRotating] = useState<ContractWebhook | null>(null);
    const [deleting, setDeleting] = useState<ContractWebhook | null>(null);
    const [chosen, setChosen] = useState<string[]>([]);
    const { t } = useTranslate();
    const formats = useFormats();

    /*
     * Spelled out per event rather than looked up by key, for the reason the
     * token screen gives about its scopes: translation keys are a typed union,
     * so a name built from a string the server sent is not a key the compiler
     * can check — and a fourth event added later should fail to build rather
     * than draw itself as "completed".
     */
    const eventLabel = (event: string) =>
        event === 'signed'
            ? t('settings.contract_webhooks.event_signed')
            : event === 'declined'
              ? t('settings.contract_webhooks.event_declined')
              : event === 'completed'
                ? t('settings.contract_webhooks.event_completed')
                : event;

    const eventHint = (event: string) =>
        event === 'signed'
            ? t('settings.contract_webhooks.event_signed_hint')
            : event === 'declined'
              ? t('settings.contract_webhooks.event_declined_hint')
              : event === 'completed'
                ? t('settings.contract_webhooks.event_completed_hint')
                : null;

    /**
     * The one line under a row that says whether this address is in good health.
     *
     * The most recent thing that happened, not both: a row that reads "laatst
     * afgeleverd maandag, laatste poging mislukt dinsdag" makes somebody work
     * out which came last, and the only question they are asking is whether it
     * works right now.
     */
    const health = (webhook: ContractWebhook) => {
        const failed = webhook.lastFailedAt;

        if (
            failed !== null &&
            (webhook.lastDeliveredAt === null ||
                failed > webhook.lastDeliveredAt)
        ) {
            return t('settings.contract_webhooks.last_failed', {
                moment: formats.dateTime.format(new Date(failed)),
            });
        }

        if (webhook.lastDeliveredAt !== null) {
            return t('settings.contract_webhooks.last_delivered', {
                moment: formats.dateTime.format(
                    new Date(webhook.lastDeliveredAt),
                ),
            });
        }

        return t('settings.contract_webhooks.never_delivered');
    };

    return (
        <>
            <Head title={t('settings.contract_webhooks.title')} />

            <SettingsSection
                title={t('settings.contract_webhooks.title')}
                description={t('settings.contract_webhooks.description')}
            >
                {/*
                    What we actually do, in one paragraph, above the form.
                    Somebody arriving here has to build the receiving end, and
                    the two things they cannot guess are that we POST JSON and
                    that there is a signature to check.
                */}
                <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                    {t('settings.contract_webhooks.intro')}
                </div>

                <Form
                    action={store.url()}
                    method="post"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    disableWhileProcessing
                    /*
                        resetOnSuccess empties the fields the form owns; the
                        ticks are React state and would otherwise stay on the
                        last choice, so the next webhook would quietly inherit
                        the events of the one before it.
                    */
                    onSuccess={() => setChosen([])}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="webhook-name">
                                    {t('settings.contract_webhooks.name')}
                                </Label>
                                <Input
                                    id="webhook-name"
                                    name="name"
                                    maxLength={60}
                                    required
                                    placeholder={t(
                                        'settings.contract_webhooks.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="webhook-url">
                                    {t('settings.contract_webhooks.url')}
                                </Label>
                                <Input
                                    id="webhook-url"
                                    name="url"
                                    type="url"
                                    required
                                    className="font-mono text-xs"
                                    placeholder={t(
                                        'settings.contract_webhooks.url_placeholder',
                                    )}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('settings.contract_webhooks.url_hint')}
                                </p>
                                <InputError message={errors.url} />
                            </div>

                            <div className="grid gap-2">
                                <Label>
                                    {t('settings.contract_webhooks.events')}
                                </Label>
                                {events.map((event) => (
                                    <label key={event} className={CHOICE_ROW}>
                                        <Checkbox
                                            checked={chosen.includes(event)}
                                            onCheckedChange={(checked) =>
                                                setChosen((held) =>
                                                    checked
                                                        ? [...held, event]
                                                        : held.filter(
                                                              (one) =>
                                                                  one !== event,
                                                          ),
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        {chosen.includes(event) && (
                                            <input
                                                type="hidden"
                                                name="events[]"
                                                value={event}
                                            />
                                        )}
                                        <span className="grid gap-1">
                                            <span className="font-medium">
                                                {eventLabel(event)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {eventHint(event)}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'settings.contract_webhooks.events_hint',
                                    )}
                                </p>
                                <InputError message={errors.events} />
                            </div>

                            <div>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    {t('settings.contract_webhooks.create')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {webhooks.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Radio className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('settings.contract_webhooks.empty')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('settings.contract_webhooks.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {webhooks.map((webhook) => (
                            <li
                                key={webhook.id}
                                className={cn(
                                    'grid gap-3 rounded-lg border p-4',
                                    // A switched-off subscription is still
                                    // listed and still readable, only visibly
                                    // asleep: it is the row somebody comes back
                                    // to in order to switch it on again.
                                    webhook.disabledAt && 'bg-muted/40',
                                )}
                            >
                                <div className="flex flex-wrap items-start gap-3">
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-medium">
                                                {webhook.name}
                                            </span>
                                            {webhook.disabledAt && (
                                                <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                    {t(
                                                        'settings.contract_webhooks.disabled',
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        <span className="block truncate font-mono text-xs text-muted-foreground">
                                            {webhook.url}
                                        </span>
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {webhook.events.map((event) => (
                                                <span
                                                    key={event}
                                                    className="mr-1.5 rounded bg-muted px-1.5 py-0.5"
                                                >
                                                    {eventLabel(event)}
                                                </span>
                                            ))}
                                        </span>
                                    </span>

                                    <span
                                        className={cn(
                                            'shrink-0 text-xs',
                                            webhook.lastFailedAt &&
                                                !webhook.lastDeliveredAt
                                                ? 'text-destructive'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {health(webhook)}
                                        {webhook.lastStatus !== null && (
                                            <span className="ml-1.5">
                                                {t(
                                                    'settings.contract_webhooks.last_status',
                                                    {
                                                        status: String(
                                                            webhook.lastStatus,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        )}
                                    </span>
                                </div>

                                {/*
                                    The secret, shown rather than hidden behind
                                    a reveal: it has to be pasted into the
                                    receiving system, and a value you cannot
                                    read back is one you lose by closing the
                                    tab. Same trade the tokens make.
                                */}
                                <div className="flex items-center gap-2">
                                    <Label
                                        htmlFor={`secret-${webhook.id}`}
                                        className="sr-only"
                                    >
                                        {t('settings.contract_webhooks.secret')}
                                    </Label>
                                    <Input
                                        id={`secret-${webhook.id}`}
                                        value={webhook.secret}
                                        readOnly
                                        className="font-mono text-xs"
                                    />
                                    <CopyButton
                                        value={webhook.secret}
                                        label={t(
                                            'settings.contract_webhooks.copy_secret',
                                        )}
                                    />
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.patch(
                                                toggle.url(webhook.id),
                                                {
                                                    enabled:
                                                        webhook.disabledAt !==
                                                        null,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {webhook.disabledAt
                                            ? t(
                                                  'settings.contract_webhooks.enable',
                                              )
                                            : t(
                                                  'settings.contract_webhooks.disable',
                                              )}
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setRotating(webhook)}
                                        aria-label={t(
                                            'settings.contract_webhooks.rotate_named',
                                            { name: webhook.name },
                                        )}
                                    >
                                        <RefreshCw className="size-3.5" />
                                        {t('settings.contract_webhooks.rotate')}
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setDeleting(webhook)}
                                        aria-label={t(
                                            'settings.contract_webhooks.delete_named',
                                            { name: webhook.name },
                                        )}
                                        className="text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <Trash2 className="size-3.5" />
                                        {t('settings.contract_webhooks.delete')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </SettingsSection>

            <AlertDialog
                open={rotating !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRotating(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('settings.contract_webhooks.rotate_question', {
                                name: rotating?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('settings.contract_webhooks.rotate_explanation')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('settings.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (rotating) {
                                    router.post(
                                        rotate.url(rotating.id),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {t('settings.contract_webhooks.rotate')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('settings.contract_webhooks.delete_question', {
                                name: deleting?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('settings.contract_webhooks.delete_explanation')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('settings.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (deleting) {
                                    router.delete(destroy.url(deleting.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {t('settings.contract_webhooks.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
