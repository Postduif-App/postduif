import { Form, router } from '@inertiajs/react';
import { Check, Copy, Download, Inbox, Lock, Package, X } from 'lucide-react';
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
import { destroy, store } from '@/routes/chat/upload-links';
import type { TranslationKey } from '@/types/translations';

type State = 'usable' | 'expired' | 'revoked' | 'exhausted';

interface SubmissionFile {
    id: number;
    name: string;
    size: number;
    url: string;
}

interface Submission {
    id: string;
    /** What the sender typed about themselves; nobody checked it. */
    name: string;
    email: string | null;
    note: string | null;
    at: string;
    size: number;
    downloadAllUrl: string;
    files: SubmissionFile[];
}

export interface UploadLinkRow {
    id: string;
    /** The whole address, token and all — this is the thing you share. */
    url: string;
    title: string;
    ownerName: string | null;
    notifyChannelName: string | null;
    isLocked: boolean;
    uploads: number;
    /** Null for as often as the sender likes. */
    maxUploads: number | null;
    expiresAt: string;
    /** When what came in is cleared, or null while the link is open. */
    clearedAt: string | null;
    createdAt: string;
    state: State;
    submissions: Submission[];
}

interface NotifyChannel {
    id: number;
    label: string;
}

export interface UploadLinkManagerProps {
    workspaceName: string;
    workspaceSlug: string;
    canCreate: boolean;
    maxTransferKb: number;
    maxTransferDays: number;
    /** The channels this member is in, to name one for the news. */
    notifyChannels: NotifyChannel[];
    seesEveryone: boolean;
    uploadLinks: UploadLinkRow[];
}

const DEAD: Record<Exclude<State, 'usable'>, TranslationKey> = {
    expired: 'panels.upload_links.dead_expired',
    revoked: 'panels.upload_links.dead_revoked',
    exhausted: 'panels.upload_links.dead_exhausted',
};

const DAYS = [1, 3, 7, 14, 30, 90];

const fieldClass =
    'h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none';

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
 * Links somebody outside can send files in through, and what came in.
 *
 * TransferManager turned around, and kept beside it on the same screen: both
 * are about files crossing the edge of the workspace, and somebody looking for
 * one will expect the other next to it.
 */
export function UploadLinkManager({
    workspaceName,
    workspaceSlug,
    canCreate,
    maxTransferKb,
    maxTransferDays,
    notifyChannels,
    seesEveryone,
    uploadLinks,
}: UploadLinkManagerProps) {
    const formats = useFormats();
    const { t, tChoice } = useTranslate();

    const [pendingRevoke, setPendingRevoke] = useState<UploadLinkRow | null>(
        null,
    );

    // Trimmed to what the workspace allows, with the ceiling itself always on
    // offer — the same reasoning as the transfer form.
    const options = DAYS.filter((days) => days <= maxTransferDays).concat(
        DAYS.includes(maxTransferDays) ? [] : [maxTransferDays],
    );

    return (
        <>
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('panels.upload_links.heading')}
                    description={
                        seesEveryone
                            ? t('panels.upload_links.description_everyone', {
                                  workspace: workspaceName,
                              })
                            : t('panels.upload_links.description_own', {
                                  workspace: workspaceName,
                              })
                    }
                />

                {canCreate && (
                    <Form
                        action={store.url({ workspace: workspaceSlug })}
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        disableWhileProcessing
                        className="space-y-4 rounded-lg border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="upload-title">
                                        {t('panels.upload_links.title_label')}
                                    </Label>
                                    <Input
                                        id="upload-title"
                                        name="title"
                                        required
                                        maxLength={120}
                                        placeholder={t(
                                            'panels.upload_links.title_placeholder',
                                        )}
                                    />
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="upload-message">
                                        {t('panels.upload_links.message_label')}
                                    </Label>
                                    <textarea
                                        id="upload-message"
                                        name="message"
                                        rows={2}
                                        maxLength={2000}
                                        placeholder={t(
                                            'panels.upload_links.message_placeholder',
                                        )}
                                        className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    />
                                    <InputError message={errors.message} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="upload-valid_for_days">
                                            {t(
                                                'panels.upload_links.validity_label',
                                            )}
                                        </Label>
                                        <select
                                            id="upload-valid_for_days"
                                            name="valid_for_days"
                                            defaultValue={String(
                                                options.at(-1) ?? 1,
                                            )}
                                            className={fieldClass}
                                        >
                                            {options.map((days) => (
                                                <option key={days} value={days}>
                                                    {tChoice(
                                                        'panels.transfers.validity_days',
                                                        days,
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.valid_for_days}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="upload-password">
                                            {t(
                                                'panels.upload_links.password_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="upload-password"
                                            name="password"
                                            type="text"
                                            minLength={6}
                                            autoComplete="off"
                                            placeholder={t(
                                                'panels.upload_links.password_placeholder',
                                            )}
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="upload-max_uploads">
                                            {t(
                                                'panels.upload_links.max_uploads_label',
                                            )}
                                        </Label>
                                        <Input
                                            id="upload-max_uploads"
                                            name="max_uploads"
                                            type="number"
                                            min={1}
                                            max={1000}
                                            placeholder={t(
                                                'panels.upload_links.max_uploads_placeholder',
                                            )}
                                        />
                                        <InputError
                                            message={errors.max_uploads}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="upload-notify_channel_id">
                                            {t(
                                                'panels.upload_links.notify_label',
                                            )}
                                        </Label>
                                        <select
                                            id="upload-notify_channel_id"
                                            name="notify_channel_id"
                                            defaultValue=""
                                            className={fieldClass}
                                        >
                                            <option value="">
                                                {t(
                                                    'panels.upload_links.notify_none',
                                                )}
                                            </option>
                                            {notifyChannels.map((channel) => (
                                                <option
                                                    key={channel.id}
                                                    value={channel.id}
                                                >
                                                    {channel.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.notify_channel_id}
                                        />
                                    </div>
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    {t('panels.upload_links.notify_hint')}{' '}
                                    {t('panels.upload_links.size_hint', {
                                        size: readableSize(
                                            maxTransferKb * 1024,
                                            formats.number,
                                        ),
                                    })}
                                </p>

                                <Button type="submit">
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Inbox className="size-4" />
                                    )}
                                    {t('panels.upload_links.submit')}
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {uploadLinks.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Inbox className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('panels.upload_links.empty_title')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('panels.upload_links.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border px-3">
                        {uploadLinks.map((link) => (
                            <li
                                key={link.id}
                                id={`upload-link-${link.id}`}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'flex items-center gap-1.5 truncate text-sm font-medium',
                                            link.state !== 'usable' &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {link.isLocked && (
                                            <Lock
                                                className="size-3 shrink-0 text-muted-foreground"
                                                aria-label={t(
                                                    'panels.upload_links.locked',
                                                )}
                                            />
                                        )}
                                        <span className="truncate">
                                            {link.title}
                                        </span>
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {link.maxUploads === null
                                            ? tChoice(
                                                  'panels.upload_links.uploads_open',
                                                  link.uploads,
                                              )
                                            : t(
                                                  'panels.upload_links.uploads_capped',
                                                  {
                                                      count: link.uploads,
                                                      max: link.maxUploads,
                                                  },
                                              )}
                                        {link.notifyChannelName &&
                                            ` · ${t('panels.upload_links.notifies', { channel: link.notifyChannelName })}`}
                                        {seesEveryone &&
                                            link.ownerName &&
                                            ` · ${t('panels.upload_links.owned_by', { name: link.ownerName })}`}
                                    </span>
                                </span>

                                <span
                                    className={cn(
                                        'shrink-0 text-xs',
                                        link.state === 'usable'
                                            ? 'text-muted-foreground'
                                            : 'text-destructive',
                                    )}
                                >
                                    {link.state === 'usable'
                                        ? t('panels.transfers.valid_until', {
                                              date: formats.date.format(
                                                  new Date(link.expiresAt),
                                              ),
                                          })
                                        : link.clearedAt
                                          ? t('panels.transfers.cleared', {
                                                state: t(DEAD[link.state]),
                                                date: formats.date.format(
                                                    new Date(link.clearedAt),
                                                ),
                                            })
                                          : t(DEAD[link.state])}
                                </span>

                                {link.state === 'usable' && (
                                    <CopyButton url={link.url} />
                                )}

                                {link.state !== 'revoked' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setPendingRevoke(link)}
                                        aria-label={t(
                                            'panels.upload_links.revoke',
                                        )}
                                        title={t('panels.upload_links.revoke')}
                                    >
                                        <X className="size-3.5" />
                                    </Button>
                                )}

                                {/*
                                    What came in, per sender. Open by default
                                    rather than folded away: unlike a download
                                    log, this is the reason anybody opens the
                                    screen.
                                */}
                                {link.submissions.length > 0 && (
                                    <ul className="w-full space-y-2 pl-1">
                                        {link.submissions.map((submission) => (
                                            <li
                                                key={submission.id}
                                                className="rounded-md bg-muted/40 p-2 text-xs"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className="min-w-0 flex-1 truncate font-medium">
                                                        {submission.name}
                                                        {submission.email && (
                                                            <span className="font-normal text-muted-foreground">
                                                                {' '}
                                                                ·{' '}
                                                                {
                                                                    submission.email
                                                                }
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="shrink-0 text-muted-foreground">
                                                        {formats.date.format(
                                                            new Date(
                                                                submission.at,
                                                            ),
                                                        )}
                                                    </span>
                                                    {submission.files.length >
                                                        1 && (
                                                        <a
                                                            href={
                                                                submission.downloadAllUrl
                                                            }
                                                            className="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-1 hover:bg-muted"
                                                        >
                                                            <Package className="size-3.5" />
                                                            {t(
                                                                'panels.upload_links.download_all',
                                                                {
                                                                    size: readableSize(
                                                                        submission.size,
                                                                        formats.number,
                                                                    ),
                                                                },
                                                            )}
                                                        </a>
                                                    )}
                                                </div>
                                                {submission.note && (
                                                    <p className="mt-1 whitespace-pre-line text-muted-foreground">
                                                        {submission.note}
                                                    </p>
                                                )}
                                                <ul className="mt-1 space-y-0.5">
                                                    {submission.files.map(
                                                        (file) => (
                                                            <li
                                                                key={file.id}
                                                                className="flex items-center gap-2"
                                                            >
                                                                <span className="min-w-0 flex-1 truncate">
                                                                    {file.name}
                                                                </span>
                                                                <span className="shrink-0 text-muted-foreground">
                                                                    {readableSize(
                                                                        file.size,
                                                                        formats.number,
                                                                    )}
                                                                </span>
                                                                {/*
                                                                    A plain anchor: the
                                                                    response is a file,
                                                                    not a page.
                                                                */}
                                                                <a
                                                                    href={
                                                                        file.url
                                                                    }
                                                                    className="shrink-0 rounded p-1 hover:bg-muted"
                                                                    aria-label={t(
                                                                        'panels.upload_links.download_file',
                                                                        {
                                                                            name: file.name,
                                                                        },
                                                                    )}
                                                                >
                                                                    <Download className="size-3.5" />
                                                                </a>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </li>
                                        ))}
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
                            {t('panels.upload_links.revoke_title')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('panels.upload_links.revoke_description')}
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
                                        uploadLink: pendingRevoke.id,
                                    }),
                                    { preserveScroll: true },
                                );
                                setPendingRevoke(null);
                            }}
                        >
                            {t('panels.upload_links.revoke_confirm')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
