import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { CircleCheck, Upload } from 'lucide-react';
import { useState } from 'react';

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

interface UploadLinkShowProps {
    uploadLink: {
        title: string;
        /** Null while the password has not been answered. */
        message: string | null;
        ownerName: string | null;
        workspaceName: string;
        expiresAt: string;
        state: State;
        /** True while the password has not been answered in this browser. */
        isLocked: boolean;
        /** How many more submissions it takes, or null for no ceiling. */
        uploadsLeft: number | null;
        maxKb: number;
        submitUrl: string;
        unlockUrl: string;
    };
}

/** Each way the link can be closed gets its own words, as on a transfer. */
const DEAD_END: Record<
    Exclude<State, 'usable'>,
    { title: TranslationKey; body: TranslationKey }
> = {
    expired: {
        title: 'auth_screens.upload_link.expired_title',
        body: 'auth_screens.upload_link.expired_body',
    },
    revoked: {
        title: 'auth_screens.upload_link.revoked_title',
        body: 'auth_screens.upload_link.revoked_body',
    },
    exhausted: {
        title: 'auth_screens.upload_link.exhausted_title',
        body: 'auth_screens.upload_link.exhausted_body',
    },
};

const textareaClass =
    'w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Sending files in through a link somebody put out for you.
 *
 * The visitor is usually a customer with no account, on whatever device they
 * had to hand, so this is one card and one form. Their name is asked because
 * the receiver has to know whose paperwork this is; nothing else is required.
 */
export default function UploadLinkShow({ uploadLink }: UploadLinkShowProps) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

    /*
     * Kept in the page rather than read back from the server: the answer to a
     * submission is "it arrived", and that belongs to this one visit. A reload
     * shows the empty form again, which is what somebody sending a second
     * batch wants.
     */
    const [sent, setSent] = useState(false);

    setLayoutProps({
        title: t('auth_screens.upload_link.title'),
        description: t('auth_screens.upload_link.description'),
    });

    const header = (
        <div className="space-y-1 text-center">
            <p className="text-sm text-muted-foreground">
                {uploadLink.ownerName
                    ? t('auth_screens.upload_link.owner_asks', {
                          name: uploadLink.ownerName,
                      })
                    : t('auth_screens.upload_link.someone_asks')}
            </p>
            <p className="text-lg font-medium">{uploadLink.title}</p>
            <p className="text-xs text-muted-foreground">
                {t('auth_screens.upload_link.via', {
                    workspace: uploadLink.workspaceName,
                })}
            </p>
        </div>
    );

    if (uploadLink.state !== 'usable' && !sent) {
        const message = DEAD_END[uploadLink.state];

        return (
            <>
                <Head title={t('auth_screens.upload_link.head')} />
                <div className="space-y-3 text-center">
                    <h2 className="text-lg font-medium">{t(message.title)}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(message.body)}
                    </p>
                </div>
            </>
        );
    }

    if (uploadLink.isLocked) {
        return (
            <>
                <Head title={t('auth_screens.upload_link.head')} />
                <div className="flex flex-col gap-6">
                    {header}
                    <p className="text-center text-sm font-medium">
                        {t('auth_screens.upload_link.password_needed')}
                    </p>

                    <Form
                        action={uploadLink.unlockUrl}
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
                                    {t('auth_screens.upload_link.unlock')}
                                </Button>
                            </>
                        )}
                    </Form>

                    <p className="text-center text-xs text-muted-foreground">
                        {t('auth_screens.upload_link.password_note')}
                    </p>
                </div>
            </>
        );
    }

    if (sent) {
        return (
            <>
                <Head title={t('auth_screens.upload_link.head')} />
                <div className="flex flex-col items-center gap-4 text-center">
                    <CircleCheck className="size-10 text-emerald-600" />
                    <div className="space-y-1">
                        <h2 className="text-lg font-medium">
                            {t('auth_screens.upload_link.received_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('auth_screens.upload_link.received_body')}
                        </p>
                    </div>
                    {uploadLink.state === 'usable' && (
                        <Button
                            variant="outline"
                            onClick={() => setSent(false)}
                        >
                            {t('auth_screens.upload_link.send_more')}
                        </Button>
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={uploadLink.title} />

            <div className="flex flex-col gap-6">
                {header}

                {uploadLink.message && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {uploadLink.message}
                    </p>
                )}

                <Form
                    action={uploadLink.submitUrl}
                    method="post"
                    resetOnSuccess
                    disableWhileProcessing
                    onSuccess={() => setSent(true)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, progress, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="files">
                                    {t('auth_screens.upload_link.files_label')}
                                </Label>
                                <Input
                                    id="files"
                                    name="files[]"
                                    type="file"
                                    multiple
                                    required
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('auth_screens.upload_link.files_hint', {
                                        size: readableSize(
                                            uploadLink.maxKb * 1024,
                                            formats.number,
                                        ),
                                    })}
                                </p>
                                <InputError
                                    message={errors.files ?? errors['files.0']}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('auth_screens.upload_link.name_label')}
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={120}
                                    autoComplete="name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth_screens.upload_link.email_label')}
                                </Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('auth_screens.upload_link.email_hint')}
                                </p>
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="note">
                                    {t('auth_screens.upload_link.note_label')}
                                </Label>
                                <textarea
                                    id="note"
                                    name="note"
                                    rows={2}
                                    maxLength={2000}
                                    placeholder={t(
                                        'auth_screens.upload_link.note_placeholder',
                                    )}
                                    className={textareaClass}
                                />
                                <InputError message={errors.note} />
                            </div>

                            {/*
                                A progress bar, as on the sending side: this
                                is for files too big for an email, and "is it
                                doing anything" is a question somebody will be
                                asking for minutes.
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
                                        {t(
                                            'auth_screens.upload_link.uploading',
                                            {
                                                percentage: Math.round(
                                                    progress.percentage ?? 0,
                                                ),
                                            },
                                        )}
                                    </p>
                                </div>
                            )}

                            <Button type="submit" className="w-full">
                                {processing && !progress && <Spinner />}
                                <Upload className="size-4" />
                                {t('auth_screens.upload_link.submit')}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="space-y-1 text-center text-xs text-muted-foreground">
                    <p>
                        {t('auth_screens.upload_link.available_until', {
                            date: formats.longDate.format(
                                new Date(uploadLink.expiresAt),
                            ),
                        })}
                    </p>
                    {uploadLink.uploadsLeft !== null && (
                        <p>
                            {tChoice(
                                'auth_screens.upload_link.uploads_left',
                                uploadLink.uploadsLeft,
                            )}
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
