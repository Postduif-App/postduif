import { Head } from '@inertiajs/react';
import { Check, Copy, Eye, Flame } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
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

function formatMoment(value: string): string {
    return new Date(value).toLocaleString('nl-NL', {
        day: 'numeric',
        month: 'long',
        hour: '2-digit',
        minute: '2-digit',
    });
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
                        {entry.isAnswered
                            ? `ingevuld door ${entry.filledBy ?? 'iemand'}${
                                  entry.filledAt
                                      ? ` op ${formatMoment(entry.filledAt)}`
                                      : ''
                              }`
                            : 'nog niet ingevuld'}
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
                        Tonen
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
                            <Check className="size-3.5 text-emerald-600" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                        {copied === value ? 'Gekopieerd' : 'Kopiëren'}
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
                    Dit was de enige keer — de waarde is nu verwijderd. Kopieer
                    hem voor je deze pagina sluit.
                </p>
            )}

            {failed && (
                <p className="text-xs text-destructive">
                    Tonen is niet gelukt. Ververs de pagina en probeer opnieuw.
                </p>
            )}

            {entry.revealedAt && value === null && !burned && (
                <p className="text-xs text-muted-foreground">
                    Eerder bekeken op {formatMoment(entry.revealedAt)}.
                </p>
            )}
        </li>
    );
}

export default function SecretAnswers({ request }: AnswersProps) {
    const answered = request.keys.filter((key) => key.isAnswered).length;

    return (
        <>
            <Head title={request.title} />

            <div className="space-y-6">
                <div className="space-y-1">
                    <h1 className="text-lg font-medium">{request.title}</h1>
                    <p className="text-sm text-muted-foreground">
                        Gevraagd in #{request.channelName} · {answered} van{' '}
                        {request.keys.length} ingevuld
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
                        <span>
                            Dit verzoek verwijdert elke waarde zodra je hem hebt
                            bekeken. Je krijgt hem één keer te zien, dus zorg
                            dat je hem meteen kunt gebruiken.
                        </span>
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
                    Alleen jij kunt deze waarden zien — beheerders van de
                    workspace niet. Na{' '}
                    {new Date(request.expiresAt).toLocaleDateString('nl-NL', {
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}{' '}
                    wordt het verzoek opgeruimd, waarden en al.
                </p>
            </div>
        </>
    );
}

SecretAnswers.layout = {
    title: 'Gevraagde gegevens',
    description: 'Wat er is ingevuld',
};
