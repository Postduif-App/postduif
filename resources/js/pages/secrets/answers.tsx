import { Head, setLayoutProps } from '@inertiajs/react';
import { Check, Copy, Eye, Flame } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import { reveal } from '@/routes/secrets';

interface AnswerKey {
    id: number;
    name: string;
    hint: string | null;
    isAnswered: boolean;
    filledBy: string | null;
    filledAt: string | null;
    revealedAt: string | null;
}

interface AnswersProps {
    request: {
        id: string;
        title: string;
        description: string | null;
        expiresAt: string;
        burnAfterReading: boolean;
        state: 'open' | 'expired' | 'revoked';
        channelName: string;
        keys: AnswerKey[];
    };
}

/**
 * One answer, hidden until asked for.
 *
 * The value is fetched on the click and kept in this component's state — never
 * in the page's props. That is the difference between a password living for the
 * seconds somebody is looking at it and living in the browser's history for as
 * long as the tab is open.
 */
function AnswerRow({
    requestId,
    entry,
}: {
    requestId: string;
    entry: AnswerKey;
}) {
    const [value, setValue] = useState<string | null>(null);
    const [burned, setBurned] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [copied, copy] = useClipboard();
    const formats = useFormats();
    const { t } = useTranslate();

    const show = async () => {
        setLoading(true);
        setFailed(false);

        try {
            const response = await fetch(
                reveal.url({ secretRequest: requestId, key: entry.id }),
                { method: 'POST', headers: mutatingHeaders() },
            );

            if (!response.ok) {
                setFailed(true);

                return;
            }

            const payload = await response.json();
            setValue(payload.value);
            setBurned(payload.burned);
        } finally {
            setLoading(false);
        }
    };

    return (
        <li className="grid gap-2 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-mono text-sm font-medium">
                        {entry.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {!entry.isAnswered
                            ? t('account.secret_answers.not_filled')
                            : entry.filledAt
                              ? t('account.secret_answers.filled_by_at', {
                                    name:
                                        entry.filledBy ??
                                        t('account.secret_answers.somebody'),
                                    moment: formats.dateTime.format(
                                        new Date(entry.filledAt),
                                    ),
                                })
                              : t('account.secret_answers.filled_by', {
                                    name:
                                        entry.filledBy ??
                                        t('account.secret_answers.somebody'),
                                })}
                    </span>
                </span>

                {entry.isAnswered && value === null && !burned && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={loading}
                        onClick={() => void show()}
                    >
                        <Eye className="size-3.5" />
                        {t('account.secret_answers.show')}
                    </Button>
                )}

                {value !== null && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => void copy(value)}
                    >
                        {copied === value ? (
                            <Check className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                        {copied === value
                            ? t('account.secret_answers.copied')
                            : t('account.secret_answers.copy')}
                    </Button>
                )}
            </div>

            {value !== null && (
                <p className="rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm break-all">
                    {value}
                </p>
            )}

            {burned && (
                <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
                    <Flame className="size-3.5" />
                    {t('account.secret_answers.burned')}
                </p>
            )}

            {failed && (
                <p className="text-xs text-destructive">
                    {t('account.secret_answers.failed')}
                </p>
            )}

            {entry.revealedAt && value === null && !burned && (
                <p className="text-xs text-muted-foreground">
                    {t('account.secret_answers.seen_before', {
                        moment: formats.dateTime.format(
                            new Date(entry.revealedAt),
                        ),
                    })}
                </p>
            )}
        </li>
    );
}

export default function SecretAnswers({ request }: AnswersProps) {
    const answered = request.keys.filter((key) => key.isAnswered).length;
    const { t } = useTranslate();
    const formats = useFormats();

    setLayoutProps({
        title: t('account.secret_answers.title'),
        description: t('account.secret_answers.description'),
    });

    return (
        <>
            <Head title={request.title} />

            <div className="space-y-6">
                <div className="space-y-1">
                    <h1 className="text-lg font-medium">{request.title}</h1>
                    <p className="text-sm text-muted-foreground">
                        {t('account.secret_answers.asked_in', {
                            channel: request.channelName,
                            filled: String(answered),
                            total: String(request.keys.length),
                        })}
                    </p>
                </div>

                {request.description && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {request.description}
                    </p>
                )}

                {request.burnAfterReading && (
                    <p
                        className={cn(
                            'flex items-start gap-2 rounded-lg border p-3 text-xs',
                            'border-amber-500/40 text-amber-700 dark:text-amber-400',
                        )}
                    >
                        <Flame className="mt-0.5 size-4 shrink-0" />
                        <span>{t('account.secret_answers.burn_note')}</span>
                    </p>
                )}

                <ul className="divide-y rounded-lg border px-3">
                    {request.keys.map((entry) => (
                        <AnswerRow
                            key={entry.id}
                            requestId={request.id}
                            entry={entry}
                        />
                    ))}
                </ul>

                <p className="text-xs text-muted-foreground">
                    {/*
                        Door de gedeelde formatter en niet met een vaste
                        'nl-NL': anders leest een Engelse pagina "After 4
                        augustus" — half vertaald is hier slechter dan niet.
                    */}
                    {t('account.secret_answers.expiry_note', {
                        date: formats.date.format(new Date(request.expiresAt)),
                    })}
                </p>
            </div>
        </>
    );
}
