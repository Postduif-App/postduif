import { Form, router } from '@inertiajs/react';
import { Check, Copy, Send, X } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { readableSize } from '@/lib/file-size';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/chat/transfers';
import { destroy as revokeRecipient } from '@/routes/chat/transfers/recipients';
import type { TranslationKey } from '@/types/translations';

type State = 'usable' | 'expired' | 'revoked' | 'exhausted';

interface AudienceOption {
    value: string;
    label: string;
    /** What the sender is choosing, in terms of what can go wrong. */
    hint: string;
}

interface Recipient {
    id: number;
    email: string;
    /** This person's own link, which is not the transfer's. */
    url: string;
    downloads: number;
    lastDownloadedAt: string | null;
    isRevoked: boolean;
}

interface LogEntry {
    id: number;
    at: string;
    /** The address or member we can name, or null for an open link. */
    who: string | null;
    ip: string | null;
    wasWholeArchive: boolean;
}

interface TransferRow {
    id: string;
    /** The whole address, token and all — this is the thing you share. */
    url: string;
    title: string | null;
    audienceLabel: string;
    senderName: string | null;
    fileCount: number;
    size: number;
    downloads: number;
    /** Null for as often as anybody likes. */
    maxDownloads: number | null;
    expiresAt: string;
    /** When the files themselves go, or null while it is still alive. */
    clearedAt: string | null;
    createdAt: string;
    state: State;
    files: string[];
    recipients: Recipient[];
    downloadLog: LogEntry[];
    lastDownloadedAt: string | null;
}

export interface TransferManagerProps {
    workspaceName: string;
    workspaceSlug: string;
    canSend: boolean;
    maxTransferKb: number;
    maxTransferDays: number;
    audienceOptions: AudienceOption[];
    /** True for an admin, who sees what colleagues sent as well as their own. */
    seesEveryone: boolean;
    transfers: TransferRow[];
}

/**
 * Why a transfer is on the list but no longer hands anything over.
 *
 * Keys rather than words: a module constant cannot call a hook, and the reader
 * whose language decides the wording is only known once something renders.
 */
const DEAD: Record<Exclude<State, 'usable'>, TranslationKey> = {
    expired: 'panels.transfers.dead_expired',
    revoked: 'panels.transfers.dead_revoked',
    exhausted: 'panels.transfers.dead_exhausted',
};

/** The wording of a count, in the language this page was rendered in. */
type Choose = (
    key: TranslationKey,
    count: number,
    replacements?: Record<string, string | number>,
) => string;

/**
 * The choices for how long a link may live, trimmed to what this workspace
 * allows.
 *
 * Filtered rather than validated after the fact: offering "30 dagen" to a
 * workspace that caps at 7 is an invitation to fill in a form that will be
 * refused, and the ceiling is already known when the page is drawn.
 *
 * The wording comes in as an argument for the same reason the constant above
 * holds keys: this is a plain function, and a plain function cannot call the
 * hook that knows which language to count in.
 */
function validityOptions(
    max: number,
    tChoice: Choose,
): { value: number; label: string }[] {
    const label = (days: number) =>
        tChoice('panels.transfers.validity_days', days);

    return [1, 3, 7, 14, 30, 90]
        .filter((days) => days <= max)
        .map((days) => ({ value: days, label: label(days) }))
        .concat(
            // Always offer the ceiling itself, so a workspace capped at 5 days
            // is not left with only "1 dag" and "3 dagen".
            [1, 3, 7, 14, 30, 90].includes(max)
                ? []
                : [{ value: max, label: label(max) }],
        );
}

/** One address per line, blanks dropped — people paste with trailing newlines. */
function splitAddresses(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);
}

function CopyButton({ url }: { url: string }) {
    const { t } = useTranslate();
    const [copied, copy] = useClipboard();
    const isCopied = copied === url;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => void copy(url)}
            aria-label={t('panels.transfers.copy_link')}
            title={t('panels.transfers.copy_link')}
        >
            {isCopied ? (
                <Check className="size-3.5 text-emerald-600" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {isCopied
                ? t('panels.transfers.copied')
                : t('panels.transfers.copy')}
        </Button>
    );
}

/**
 * Sending files by link, and the list of what is still out there.
 *
 * A component rather than a page since the screen moved out of workspace
 * settings and into the app's own shell: what it draws did not change, only
 * where it hangs. Everything it needs is handed in, so it stays indifferent to
 * which shell that is.
 */
export function TransferManager({
    workspaceName,
    workspaceSlug,
    canSend,
    maxTransferKb,
    maxTransferDays,
    audienceOptions,
    seesEveryone,
    transfers,
}: TransferManagerProps) {
    const formats = useFormats();
    const { t, tChoice } = useTranslate();

    /*
     * The transfer waiting for a yes. Withdrawing cannot be undone and the link
     * may already be in somebody's mail, so it is worth one question — the same
     * one an invite link gets.
     */
    const [pendingRevoke, setPendingRevoke] = useState<TransferRow | null>(
        null,
    );

    /*
     * Which audience is picked, so the address field can appear for the one
     * that needs it. In React state rather than read off the DOM because the
     * field has to appear the moment the radio changes, not on submit.
     */
    const [audience, setAudience] = useState('everyone');

    /** The addresses as typed, one per line. */
    const [addresses, setAddresses] = useState('');

    const options = validityOptions(maxTransferDays, tChoice);

    return (
        <>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('panels.transfers.heading')}
                    description={
                        seesEveryone
                            ? t('panels.transfers.description_everyone', {
                                  workspace: workspaceName,
                              })
                            : t('panels.transfers.description_own', {
                                  workspace: workspaceName,
                              })
                    }
                />

                {canSend && (
                    <Form
                        action={store.url({ workspace: workspaceSlug })}
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        disableWhileProcessing
                        className="space-y-4 rounded-lg border p-4"
                    >
                        {({ processing, progress, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="files">
                                        {t('panels.transfers.files_label')}
                                    </Label>
                                    <Input
                                        id="files"
                                        name="files[]"
                                        type="file"
                                        multiple
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('panels.transfers.files_hint', {
                                            size: readableSize(
                                                maxTransferKb * 1024,
                                                formats.number,
                                            ),
                                        })}
                                    </p>
                                    <InputError message={errors.files} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="title">
                                        {t('panels.transfers.title_label')}
                                    </Label>
                                    <Input
                                        id="title"
                                        name="title"
                                        maxLength={120}
                                        placeholder={t(
                                            'panels.transfers.title_placeholder',
                                        )}
                                    />
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="message">
                                        {t('panels.transfers.message_label')}
                                    </Label>
                                    <textarea
                                        id="message"
                                        name="message"
                                        rows={2}
                                        maxLength={2000}
                                        placeholder={t(
                                            'panels.transfers.message_placeholder',
                                        )}
                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    />
                                    <InputError message={errors.message} />
                                </div>

                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        {t('panels.transfers.audience_legend')}
                                    </legend>
                                    {audienceOptions.map((option) => (
                                        <label
                                            key={option.value}
                                            className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/50"
                                        >
                                            <input
                                                type="radio"
                                                name="audience"
                                                value={option.value}
                                                checked={
                                                    audience === option.value
                                                }
                                                onChange={() =>
                                                    setAudience(option.value)
                                                }
                                                className="mt-0.5"
                                            />
                                            <span className="min-w-0">
                                                <span className="block font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {option.hint}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                    <InputError message={errors.audience} />
                                </fieldset>

                                {audience === 'named-recipients' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="recipients">
                                            {t(
                                                'panels.transfers.recipients_label',
                                            )}
                                        </Label>
                                        {/*
                                            One address per line, split into
                                            separate fields on submit: a
                                            repeatable input row would be more
                                            machinery than this deserves, and
                                            pasting a column out of a mail is
                                            how people actually have these
                                            addresses to hand.
                                        */}
                                        <textarea
                                            id="recipients"
                                            rows={3}
                                            value={addresses}
                                            onChange={(event) =>
                                                setAddresses(event.target.value)
                                            }
                                            placeholder={
                                                'anna@klant.nl\nbram@klant.nl'
                                            }
                                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        />
                                        {splitAddresses(addresses).map(
                                            (address, index) => (
                                                <input
                                                    key={`${address}-${index}`}
                                                    type="hidden"
                                                    name="recipients[]"
                                                    value={address}
                                                />
                                            ),
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'panels.transfers.recipients_hint',
                                            )}
                                        </p>
                                        <InputError
                                            message={
                                                errors.recipients ??
                                                errors['recipients.0']
                                            }
                                        />
                                    </div>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="valid_for_days">
                                            {t(
                                                'panels.transfers.validity_label',
                                            )}
                                        </Label>
                                        <select
                                            id="valid_for_days"
                                            name="valid_for_days"
                                            defaultValue={String(
                                                options.at(-1)?.value ?? 1,
                                            )}
                                            className="h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {options.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.valid_for_days}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            {t(
                                                'panels.transfers.password_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="text"
                                            minLength={6}
                                            autoComplete="off"
                                            placeholder={t(
                                                'panels.transfers.password_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="max_downloads">
                                            {t(
                                                'panels.transfers.max_downloads_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="max_downloads"
                                            name="max_downloads"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            placeholder={t(
                                                'panels.transfers.max_downloads_placeholder',
                                            )}
                                        />
                                        <InputError
                                            message={errors.max_downloads}
                                        />
                                    </div>
                                </div>

                                {/*
                                    Said plainly, because it is the mistake this
                                    feature invites: a password mailed beside
                                    the link it protects is not a second lock.
                                */}
                                <p className="text-xs text-muted-foreground">
                                    {t('panels.transfers.password_warning')}
                                </p>

                                {/*
                                    A progress bar rather than a spinner, and
                                    not for decoration: this form exists for
                                    files that are too big for a message, so
                                    "is it doing anything" is a real question
                                    somebody will be asking for minutes.
                                */}
                                {progress && (
                                    <div className="space-y-1">
                                        <div
                                            role="progressbar"
                                            aria-valuenow={Math.round(
                                                progress.percentage ?? 0,
                                            )}
                                            aria-valuemin={0}
                                            aria-valuemax={100}
                                            className="h-1.5 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                className="h-full bg-primary transition-[width]"
                                                style={{
                                                    width: `${progress.percentage ?? 0}%`,
                                                }}
                                            />
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {t('panels.transfers.uploading', {
                                                percentage: Math.round(
                                                    progress.percentage ?? 0,
                                                ),
                                            })}
                                        </p>
                                    </div>
                                )}

                                <Button type="submit">
                                    {processing && !progress && <Spinner />}
                                    <Send className="size-4" />
                                    {t('panels.transfers.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {transfers.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Send className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('panels.transfers.empty_title')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('panels.transfers.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border px-3">
                        {transfers.map((transfer) => (
                            <li
                                key={transfer.id}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block truncate text-sm font-medium',
                                            transfer.state !== 'usable' &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {transfer.title ??
                                            transfer.files[0] ??
                                            t('panels.transfers.untitled')}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {tChoice(
                                            'panels.transfers.file_count',
                                            transfer.fileCount,
                                        )}
                                        {' · '}
                                        {readableSize(
                                            transfer.size,
                                            formats.number,
                                        )}
                                        {' · '}
                                        {transfer.maxDownloads === null
                                            ? t(
                                                  'panels.transfers.downloads_open',
                                                  { count: transfer.downloads },
                                              )
                                            : t(
                                                  'panels.transfers.downloads_capped',
                                                  {
                                                      count: transfer.downloads,
                                                      max: transfer.maxDownloads,
                                                  },
                                              )}
                                        {' · '}
                                        {transfer.audienceLabel.toLowerCase()}
                                        {seesEveryone &&
                                            transfer.senderName &&
                                            ` · ${t('panels.transfers.sent_by', { name: transfer.senderName })}`}
                                    </span>
                                </span>

                                <span
                                    className={cn(
                                        'shrink-0 text-xs',
                                        transfer.state === 'usable'
                                            ? 'text-muted-foreground'
                                            : 'text-destructive',
                                    )}
                                >
                                    {transfer.state === 'usable'
                                        ? t('panels.transfers.valid_until', {
                                              date: formats.date.format(
                                                  new Date(transfer.expiresAt),
                                              ),
                                          })
                                        : transfer.clearedAt
                                          ? t('panels.transfers.cleared', {
                                                state: t(DEAD[transfer.state]),
                                                date: formats.date.format(
                                                    new Date(
                                                        transfer.clearedAt,
                                                    ),
                                                ),
                                            })
                                          : t(DEAD[transfer.state])}
                                </span>

                                {transfer.state === 'usable' && (
                                    <CopyButton url={transfer.url} />
                                )}

                                {transfer.state !== 'revoked' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setPendingRevoke(transfer)
                                        }
                                        aria-label={t(
                                            'panels.transfers.revoke',
                                        )}
                                        title={t('panels.transfers.revoke')}
                                    >
                                        <X className="size-3.5" />
                                    </Button>
                                )}

                                {/*
                                    Who it went to, and who has actually
                                    fetched it. The counts are the reason this
                                    audience is worth choosing: a forwarded link
                                    shows up as one address counting twice.
                                */}
                                {/*
                                    The recent handovers, folded away. It is the
                                    answer to "is my link doing the rounds", and
                                    that is a question somebody asks once — not
                                    a table that should sit open on a settings
                                    screen holding IP addresses in view.
                                */}
                                {transfer.downloadLog.length > 0 && (
                                    <details className="w-full">
                                        <summary className="cursor-pointer text-xs text-muted-foreground">
                                            {t('panels.transfers.log_summary', {
                                                count: transfer.downloadLog
                                                    .length,
                                            })}
                                        </summary>
                                        <ul className="mt-1 space-y-0.5 pl-1">
                                            {transfer.downloadLog.map(
                                                (entry) => (
                                                    <li
                                                        key={entry.id}
                                                        className="flex items-center gap-2 text-xs text-muted-foreground"
                                                    >
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {entry.who ??
                                                                entry.ip ??
                                                                t(
                                                                    'panels.transfers.log_unknown',
                                                                )}
                                                        </span>
                                                        <span className="shrink-0">
                                                            {entry.wasWholeArchive
                                                                ? t(
                                                                      'panels.transfers.log_whole_archive',
                                                                  )
                                                                : t(
                                                                      'panels.transfers.log_single_file',
                                                                  )}
                                                        </span>
                                                        <span className="shrink-0">
                                                            {formats.date.format(
                                                                new Date(
                                                                    entry.at,
                                                                ),
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </details>
                                )}

                                {transfer.recipients.length > 0 && (
                                    <ul className="w-full space-y-1 pl-1">
                                        {transfer.recipients.map(
                                            (recipient) => (
                                                <li
                                                    key={recipient.id}
                                                    className="flex items-center gap-2 text-xs text-muted-foreground"
                                                >
                                                    <span
                                                        className={cn(
                                                            'min-w-0 flex-1 truncate',
                                                            recipient.isRevoked &&
                                                                'line-through',
                                                        )}
                                                    >
                                                        {recipient.email}
                                                    </span>
                                                    <span className="shrink-0">
                                                        {recipient.isRevoked
                                                            ? t(
                                                                  'panels.transfers.recipient_revoked',
                                                              )
                                                            : t(
                                                                  'panels.transfers.recipient_downloads',
                                                                  {
                                                                      count: recipient.downloads,
                                                                  },
                                                              )}
                                                    </span>
                                                    {!recipient.isRevoked && (
                                                        <>
                                                            <CopyButton
                                                                url={
                                                                    recipient.url
                                                                }
                                                            />
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        revokeRecipient.url(
                                                                            {
                                                                                workspace:
                                                                                    workspaceSlug,
                                                                                transfer:
                                                                                    transfer.id,
                                                                                recipient:
                                                                                    recipient.id,
                                                                            },
                                                                        ),
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                                aria-label={t(
                                                                    'panels.transfers.revoke_recipient',
                                                                    {
                                                                        email: recipient.email,
                                                                    },
                                                                )}
                                                                title={t(
                                                                    'panels.transfers.revoke_recipient',
                                                                    {
                                                                        email: recipient.email,
                                                                    },
                                                                )}
                                                            >
                                                                <X className="size-3" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <AlertDialog
                open={pendingRevoke !== null}
                onOpenChange={(open) => !open && setPendingRevoke(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('panels.transfers.revoke_title')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('panels.transfers.revoke_description')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('panels.transfers.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (pendingRevoke === null) {
                                    return;
                                }

                                router.delete(
                                    destroy.url({
                                        workspace: workspaceSlug,
                                        transfer: pendingRevoke.id,
                                    }),
                                    { preserveScroll: true },
                                );
                                setPendingRevoke(null);
                            }}
                        >
                            {t('panels.transfers.revoke_confirm')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
