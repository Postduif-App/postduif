import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { Download, FileDown, Package } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { readableSize } from '@/lib/file-size';
import type { TranslationKey } from '@/types/translations';

type State = 'usable' | 'expired' | 'revoked' | 'exhausted';

interface TransferFile {
    id: number;
    name: string;
    size: number;
    url: string;
}

interface TransferShowProps {
    transfer: {
        title: string | null;
        message: string | null;
        senderName: string | null;
        workspaceName: string;
        expiresAt: string;
        downloadsLeft: number | null;
        /** True while the password has not been answered in this browser. */
        isLocked: boolean;
        unlockUrl: string;
        state: State;
        files: TransferFile[];
        downloadAllUrl: string | null;
    };
}

/**
 * Each reason gets its own words, which is why the server sends one of three
 * rather than a bare "no". The advice differs: a link that ran out may be worth
 * asking about again, a withdrawn one probably was withdrawn on purpose.
 *
 * The words live in lang/nl and lang/en; only which pair to use is decided
 * here. Whoever follows a download link is the reader least likely to have an
 * account, and so the reader most likely to be reading in their own language.
 */
const DEAD_END: Record<
    Exclude<State, 'usable'>,
    { title: TranslationKey; body: TranslationKey }
> = {
    expired: {
        title: 'auth_screens.transfer.expired_title',
        body: 'auth_screens.transfer.expired_body',
    },
    revoked: {
        title: 'auth_screens.transfer.revoked_title',
        body: 'auth_screens.transfer.revoked_body',
    },
    exhausted: {
        title: 'auth_screens.transfer.exhausted_title',
        body: 'auth_screens.transfer.exhausted_body',
    },
};

export default function TransferShow({ transfer }: TransferShowProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    setLayoutProps({
        title: t('auth_screens.transfer.title'),
        description: t('auth_screens.transfer.description'),
    });

    if (transfer.state !== 'usable') {
        const message = DEAD_END[transfer.state];

        return (
            <>
                <Head title={t('auth_screens.transfer.head')} />
                <div className="space-y-3 text-center">
                    <h2 className="text-lg font-medium">{t(message.title)}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(message.body)}
                    </p>
                </div>
            </>
        );
    }

    /*
     * The lock is handled before the file list rather than beside it: what the
     * visitor needs here is one field and nothing else, and showing file names
     * above a password box would be giving away the part that is being
     * protected.
     */
    if (transfer.isLocked) {
        return (
            <>
                <Head title={t('auth_screens.transfer.head')} />
                <div className="flex flex-col gap-6">
                    <div className="space-y-1 text-center">
                        <p className="text-sm text-muted-foreground">
                            {transfer.senderName
                                ? t('auth_screens.transfer.sender_sent_files', {
                                      name: transfer.senderName,
                                  })
                                : t('auth_screens.transfer.files_waiting')}
                        </p>
                        <p className="text-lg font-medium">
                            {t('auth_screens.transfer.password_needed')}
                        </p>
                    </div>

                    <Form
                        action={transfer.unlockUrl}
                        method="post"
                        disableWhileProcessing
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('auth_screens.fields.password')}
                                    </Label>
                                    <Input
                                        id="password"
                                        name="password"
                                        type="password"
                                        required
                                        autoFocus
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <Button type="submit" className="w-full">
                                    {processing && <Spinner />}
                                    {t('auth_screens.transfer.unlock')}
                                </Button>
                            </>
                        )}
                    </Form>

                    <p className="text-center text-xs text-muted-foreground">
                        {t('auth_screens.transfer.password_note')}
                    </p>
                </div>
            </>
        );
    }

    const total = transfer.files.reduce((sum, file) => sum + file.size, 0);

    return (
        <>
            <Head title={transfer.title ?? t('auth_screens.transfer.head')} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {transfer.senderName
                            ? t('auth_screens.transfer.sender_sent', {
                                  name: transfer.senderName,
                              })
                            : t('auth_screens.transfer.something_waiting')}
                    </p>
                    <p className="text-lg font-medium">
                        {transfer.title ??
                            tChoice(
                                'auth_screens.transfer.file_count',
                                transfer.files.length,
                            )}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('auth_screens.transfer.via', {
                            workspace: transfer.workspaceName,
                        })}
                    </p>
                </div>

                {transfer.message && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {transfer.message}
                    </p>
                )}

                <ul className="divide-y rounded-lg border">
                    {transfer.files.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-3 p-3 text-sm"
                        >
                            <FileDown className="size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate">
                                {file.name}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {readableSize(file.size, formats.number)}
                            </span>
                            {/*
                                A plain anchor rather than an Inertia Link: the
                                response is a file, not a page, and asking
                                Inertia to navigate to it would leave the visit
                                hanging on a response it cannot render.
                            */}
                            <a
                                href={file.url}
                                className="shrink-0 rounded p-1.5 hover:bg-muted"
                                aria-label={t(
                                    'auth_screens.transfer.download_file',
                                    { name: file.name },
                                )}
                            >
                                <Download className="size-4" />
                            </a>
                        </li>
                    ))}
                </ul>

                {transfer.downloadAllUrl && transfer.files.length > 1 && (
                    <Button asChild className="w-full">
                        <a href={transfer.downloadAllUrl}>
                            <Package className="size-4" />
                            {t('auth_screens.transfer.download_all', {
                                size: readableSize(total, formats.number),
                            })}
                        </a>
                    </Button>
                )}

                <div className="space-y-1 text-center text-xs text-muted-foreground">
                    <p>
                        {t('auth_screens.transfer.available_until', {
                            date: formats.longDate.format(
                                new Date(transfer.expiresAt),
                            ),
                        })}
                    </p>
                    {/*
                        Said out loud because the counting is not obvious:
                        fetching the files one by one costs one download each,
                        while the archive costs one for the lot. Somebody who
                        knows that can choose; somebody who does not runs out
                        halfway through and has no idea why.
                    */}
                    {transfer.downloadsLeft !== null && (
                        <p>
                            {tChoice(
                                'auth_screens.transfer.downloads_left',
                                transfer.downloadsLeft,
                            )}
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
