import { Form, Head, router } from '@inertiajs/react';
import { Bot, Check, Copy, X } from 'lucide-react';
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
import { destroy, store } from '@/routes/mcp-tokens';

interface McpToken {
    id: number;
    name: string;
    /** Readable again on purpose; null once revoked. */
    token: string | null;
    lastUsedAt: string | null;
    revokedAt: string | null;
    createdAt: string | null;
}

interface McpTokensProps {
    /** Where an MCP client connects. */
    endpoint: string;
    tokens: McpToken[];
}

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

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
 * The tokens this member handed to an AI client.
 *
 * Deliberately blunt about what a token is: it acts as you, everywhere you can
 * go. That is a bigger key than a webhook — which posts as a bot into one
 * channel — and the screen should say so rather than let somebody find out.
 */
export default function McpTokens({ endpoint, tokens }: McpTokensProps) {
    const [revoking, setRevoking] = useState<McpToken | null>(null);

    return (
        <>
            <Head title="AI-toegang" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI-toegang"
                    description="Laat een AI-client meelezen en meepraten namens jou"
                />

                <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                    <p className="font-medium">Wat een token kan</p>
                    <p className="mt-1 text-muted-foreground">
                        Een token handelt als jij: het ziet elk kanaal dat jij
                        kunt zien en kan berichten plaatsen waar jij dat kunt.
                        Het geldt voor al je workspaces. Deel er dus geen, en
                        trek er een in zodra je hem niet meer gebruikt.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="mcp-endpoint">Adres van de server</Label>
                    <div className="flex items-center gap-2">
                        <Input
                            id="mcp-endpoint"
                            value={endpoint}
                            readOnly
                            className="font-mono text-xs"
                        />
                        <CopyButton value={endpoint} label="Adres kopiëren" />
                    </div>
                </div>

                <Form
                    action={store.url()}
                    method="post"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    disableWhileProcessing
                    className="grid gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor="token-name">Nieuw token</Label>
                            <div className="flex items-start gap-2">
                                <span className="grid flex-1 gap-1">
                                    <Input
                                        id="token-name"
                                        name="name"
                                        maxLength={60}
                                        required
                                        placeholder="Waar ga je hem gebruiken? Bijvoorbeeld: Claude op mijn laptop"
                                    />
                                    <InputError message={errors.name} />
                                </span>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Aanmaken
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {tokens.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Bot className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            Nog geen tokens
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Maak er een aan en plak hem in je MCP-client.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border px-3">
                        {tokens.map((token) => (
                            <li
                                key={token.id}
                                className="flex flex-wrap items-center gap-3 py-3"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {token.name}
                                    </span>
                                    <span className="block truncate font-mono text-xs text-muted-foreground">
                                        {token.token ?? '— ingetrokken —'}
                                    </span>
                                </span>

                                <span
                                    className={cn(
                                        'shrink-0 text-xs',
                                        token.revokedAt
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {token.revokedAt
                                        ? 'ingetrokken'
                                        : token.lastUsedAt
                                          ? `laatst gebruikt ${MOMENT_FORMAT.format(
                                                new Date(token.lastUsedAt),
                                            )}`
                                          : 'nog niet gebruikt'}
                                </span>

                                {token.token && (
                                    <CopyButton
                                        value={token.token}
                                        label="Token kopiëren"
                                    />
                                )}

                                {!token.revokedAt && (
                                    <button
                                        type="button"
                                        onClick={() => setRevoking(token)}
                                        aria-label={`${token.name} intrekken`}
                                        title="Intrekken"
                                        className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <AlertDialog
                open={revoking !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRevoking(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {revoking?.name} intrekken?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            De client die hem gebruikt verliest direct toegang.
                            Wat er met dit token is gezegd blijft staan — dat
                            zijn gewone berichten van jou.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Annuleren</AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (revoking) {
                                    router.delete(destroy.url(revoking.id), {
                                        preserveScroll: true,
                                    });
                                }
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
