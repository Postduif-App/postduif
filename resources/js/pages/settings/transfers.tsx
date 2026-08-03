import { Form, Head, router } from '@inertiajs/react';
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
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/chat/transfers';
import { destroy as revokeRecipient } from '@/routes/chat/transfers/recipients';

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

interface TransfersProps {
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

/** Why a transfer is on the list but no longer hands anything over. */
const DEAD: Record<Exclude<State, 'usable'>, string> = {
    expired: 'verlopen',
    revoked: 'ingetrokken',
    exhausted: 'opgebruikt',
};

const DATE_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    day: 'numeric',
    month: 'long',
});

function humanSize(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit >= 2 && value < 100 ? 1 : 0).replace('.', ',')} ${units[unit]}`;
}

/**
 * The choices for how long a link may live, trimmed to what this workspace
 * allows.
 *
 * Filtered rather than validated after the fact: offering "30 dagen" to a
 * workspace that caps at 7 is an invitation to fill in a form that will be
 * refused, and the ceiling is already known when the page is drawn.
 */
function validityOptions(max: number): { value: number; label: string }[] {
    return [1, 3, 7, 14, 30, 90]
        .filter((days) => days <= max)
        .map((days) => ({ value: days, label: `${days} dagen` }))
        .concat(
            // Always offer the ceiling itself, so a workspace capped at 5 days
            // is not left with only "1 dag" and "3 dagen".
            [1, 3, 7, 14, 30, 90].includes(max)
                ? []
                : [{ value: max, label: `${max} dagen` }],
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
    const [copied, copy] = useClipboard();
    const isCopied = copied === url;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => void copy(url)}
            aria-label="Downloadlink kopiëren"
            title="Downloadlink kopiëren"
        >
            {isCopied ? (
                <Check className="size-3.5 text-emerald-600" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {isCopied ? 'Gekopieerd' : 'Kopiëren'}
        </Button>
    );
}

export default function WorkspaceTransfers({
    workspaceName,
    workspaceSlug,
    canSend,
    maxTransferKb,
    maxTransferDays,
    audienceOptions,
    seesEveryone,
    transfers,
}: TransfersProps) {
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

    const options = validityOptions(maxTransferDays);

    return (
        <>
            <Head title="Workspace — bestanden versturen" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Bestanden versturen"
                    description={
                        seesEveryone
                            ? `Alles wat vanuit ${workspaceName} klaarstaat achter een downloadlink`
                            : `Wat jij vanuit ${workspaceName} hebt klaargezet`
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
                                    <Label htmlFor="files">Bestanden</Label>
                                    <Input
                                        id="files"
                                        name="files[]"
                                        type="file"
                                        multiple
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Samen maximaal{' '}
                                        {humanSize(maxTransferKb * 1024)}. Elk
                                        bestandstype mag — het gaat er aan de
                                        andere kant altijd als download uit,
                                        nooit als pagina.
                                    </p>
                                    <InputError message={errors.files} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="title">
                                        Onderwerp (optioneel)
                                    </Label>
                                    <Input
                                        id="title"
                                        name="title"
                                        maxLength={120}
                                        placeholder="Offerte week 32"
                                    />
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="message">
                                        Bericht (optioneel)
                                    </Label>
                                    <textarea
                                        id="message"
                                        name="message"
                                        rows={2}
                                        maxLength={2000}
                                        placeholder="Laat maar weten wat je ervan vindt."
                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    />
                                    <InputError message={errors.message} />
                                </div>

                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        Wie deze link mag gebruiken
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
                                            E-mailadressen
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
                                            Eén per regel. Iedereen krijgt een
                                            eigen link gemaild; de link
                                            hierboven werkt dan niet meer op
                                            zichzelf.
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
                                            Link blijft geldig
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
                                            Wachtwoord (optioneel)
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="text"
                                            minLength={6}
                                            autoComplete="off"
                                            placeholder="Geen"
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="max_downloads">
                                            Maximaal aantal downloads
                                        </Label>
                                        <Input
                                            id="max_downloads"
                                            name="max_downloads"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            placeholder="Onbeperkt"
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
                                    Stuur een wachtwoord altijd los van de link
                                    — via een appje of aan de telefoon.
                                    Zichtbaar getypt, want dit is geen
                                    accountwachtwoord maar iets wat je moet
                                    kunnen voorlezen.
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
                                            Bezig met uploaden —{' '}
                                            {Math.round(
                                                progress.percentage ?? 0,
                                            )}
                                            %
                                        </p>
                                    </div>
                                )}

                                <Button type="submit">
                                    {processing && !progress && <Spinner />}
                                    <Send className="size-4" />
                                    Klaarzetten
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {transfers.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Send className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            Er staat niets klaar
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Handig voor het bestand dat niet in een bericht
                            past. De ontvanger heeft geen account nodig — de
                            link is genoeg.
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
                                            'Zonder onderwerp'}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {transfer.fileCount}{' '}
                                        {transfer.fileCount === 1
                                            ? 'bestand'
                                            : 'bestanden'}{' '}
                                        · {humanSize(transfer.size)} ·{' '}
                                        {transfer.downloads}
                                        {transfer.maxDownloads === null
                                            ? 'x opgehaald'
                                            : ` van ${transfer.maxDownloads} opgehaald`}
                                        {' · '}
                                        {transfer.audienceLabel.toLowerCase()}
                                        {seesEveryone &&
                                            transfer.senderName &&
                                            ` · van ${transfer.senderName}`}
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
                                        ? `tot ${DATE_FORMAT.format(new Date(transfer.expiresAt))}`
                                        : transfer.clearedAt
                                          ? `${DEAD[transfer.state]} · bestanden weg op ${DATE_FORMAT.format(new Date(transfer.clearedAt))}`
                                          : DEAD[transfer.state]}
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
                                        aria-label="Verzending intrekken"
                                        title="Verzending intrekken"
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
                                            Laatste downloads (
                                            {transfer.downloadLog.length})
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
                                                                'onbekend'}
                                                        </span>
                                                        <span className="shrink-0">
                                                            {entry.wasWholeArchive
                                                                ? 'alles'
                                                                : '1 bestand'}
                                                        </span>
                                                        <span className="shrink-0">
                                                            {DATE_FORMAT.format(
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
                                                            ? 'ingetrokken'
                                                            : `${recipient.downloads}x opgehaald`}
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
                                                                aria-label={`Link voor ${recipient.email} intrekken`}
                                                                title={`Link voor ${recipient.email} intrekken`}
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
                            Deze verzending intrekken?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            De link stopt meteen met werken. Wie hem al heeft,
                            krijgt te zien dat de verzending is ingetrokken —
                            wat al gedownload is, blijft natuurlijk waar het is.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Annuleren</AlertDialogCancel>
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
                            Intrekken
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
