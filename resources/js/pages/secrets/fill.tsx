import { Form, Head } from '@inertiajs/react';
import { Check, ShieldCheck } from 'lucide-react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { fill } from '@/routes/secrets';

interface SecretKey {
    id: number;
    name: string;
    hint: string | null;
    /** Whether somebody has already answered it. Never the answer itself. */
    isAnswered: boolean;
}

interface FillProps {
    request: {
        id: string;
        title: string;
        description: string | null;
        requesterName: string;
        expiresAt: string;
        isOpen: boolean;
        state: 'open' | 'expired' | 'revoked';
        burnAfterReading: boolean;
        keys: SecretKey[];
    };
}

const CLOSED: Record<string, string> = {
    expired:
        'Dit verzoek is verlopen. Vraag degene die het stuurde om een nieuw verzoek.',
    revoked:
        'Dit verzoek is ingetrokken. Neem contact op als je nog iets moet doorgeven.',
};

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('nl-NL', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/**
 * Answering a request for values that should not be typed into a chat.
 *
 * The page is deliberately one-way. There is no field showing what was
 * submitted, no "check your answer" step and no way back — once a value is in,
 * the only person who ever sees it again is the one who asked. Saying that
 * plainly before somebody types is the honest thing to do, and it is also what
 * stops them from using the box as a notepad.
 */
export default function SecretFill({ request }: FillProps) {
    const open = request.state === 'open';
    const remaining = request.keys.filter((key) => !key.isAnswered);

    return (
        <>
            <Head title={request.title} />

            <div className="flex flex-col gap-6">
                <div className="space-y-1 text-center">
                    <p className="text-sm text-muted-foreground">
                        {request.requesterName} vraagt je om
                    </p>
                    <p className="text-lg font-medium">{request.title}</p>
                </div>

                {request.description && (
                    <p className="rounded-lg border bg-muted/40 p-3 text-sm whitespace-pre-line">
                        {request.description}
                    </p>
                )}

                {!open && (
                    <p className="rounded-lg border border-destructive/40 p-3 text-sm text-muted-foreground">
                        {CLOSED[request.state]}
                    </p>
                )}

                {open && remaining.length === 0 && (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        Alles is al ingevuld. Er is niets meer voor je te doen.
                    </p>
                )}

                {open && remaining.length > 0 && (
                    <Form
                        action={fill.url(request.id)}
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        disableWhileProcessing
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {request.keys.map((key) => (
                                    <div key={key.id} className="grid gap-2">
                                        <Label htmlFor={`key-${key.id}`}>
                                            {key.name}
                                        </Label>

                                        {key.isAnswered ? (
                                            <p className="flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                                <Check className="size-4 text-emerald-600" />
                                                Al ingevuld
                                            </p>
                                        ) : (
                                            <Input
                                                id={`key-${key.id}`}
                                                name={`values[${key.id}]`}
                                                type="text"
                                                autoComplete="off"
                                                spellCheck={false}
                                                className="font-mono"
                                            />
                                        )}

                                        {key.hint && !key.isAnswered && (
                                            <p className="text-xs text-muted-foreground">
                                                {key.hint}
                                            </p>
                                        )}
                                    </div>
                                ))}

                                <InputError message={errors.values} />

                                {/*
                                    Said before they type, not after. Somebody
                                    who expects to be able to check their answer
                                    later will paste carelessly.
                                */}
                                <p className="flex items-start gap-2 rounded-lg border p-3 text-xs text-muted-foreground">
                                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        Wat je invult is daarna alleen nog voor{' '}
                                        {request.requesterName} te zien — voor
                                        jou niet meer, en voor de rest van dit
                                        kanaal nooit. Controleer het dus voor je
                                        verstuurt.
                                        {request.burnAfterReading && (
                                            <>
                                                {' '}
                                                Zodra {
                                                    request.requesterName
                                                }{' '}
                                                het bekeken heeft, wordt het
                                                verwijderd.
                                            </>
                                        )}
                                    </span>
                                </p>

                                <Button type="submit" className="w-full">
                                    {processing && <Spinner />}
                                    Versturen
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <p className="text-center text-xs text-muted-foreground">
                    Dit verzoek verloopt op {formatDate(request.expiresAt)}.
                </p>
            </div>
        </>
    );
}

SecretFill.layout = {
    title: 'Gevraagd om gegevens',
    description: 'Vul in wat er gevraagd wordt',
};
