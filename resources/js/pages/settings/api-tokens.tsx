import { Form, Head, router } from '@inertiajs/react';
import { Bot, Check, Copy, X } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/api-tokens';

interface ApiToken {
    id: number;
    name: string;
    /** Readable again on purpose; null once revoked. */
    token: string | null;
    lastUsedAt: string | null;
    revokedAt: string | null;
    createdAt: string | null;
}

interface ApiTokensProps {
    /** Where an MCP client connects. */
    endpoint: string;
    /** Where the plain HTTP API lives; the same token opens it. */
    apiEndpoint: string;
    tokens: ApiToken[];
}

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
export default function ApiTokens({
    endpoint,
    apiEndpoint,
    tokens,
}: ApiTokensProps) {
    const [revoking, setRevoking] = useState<ApiToken | null>(null);
    const { t } = useTranslate();
    const formats = useFormats();

    return (
        <>
            <Head title={t('settings.api_tokens.title')} />

            <SettingsSection
                title={t('settings.api_tokens.title')}
                description={t('settings.api_tokens.description')}
            >
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                    <p className="font-medium">
                        {t('settings.api_tokens.warning_title')}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        {t('settings.api_tokens.warning')}
                    </p>
                </div>

                {/*
                    The two addresses in one block. They are reference rather
                    than something to fill in, and loose among the fields they
                    read as four more things this screen wants from you.
                */}
                <div className="grid gap-5 rounded-lg border bg-muted/30 p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="mcp-endpoint">
                            {t('settings.api_tokens.endpoint')}
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="mcp-endpoint"
                                value={endpoint}
                                readOnly
                                className="font-mono text-xs"
                            />
                            <CopyButton
                                value={endpoint}
                                label={t('settings.api_tokens.copy_endpoint')}
                            />
                        </div>
                        {/*
                        Said here rather than left to be discovered: a token is
                        the first thing somebody reaches for on this screen, and
                        this is the one address that does not want one.
                    */}
                        <p className="text-xs text-muted-foreground">
                            {t('settings.api_tokens.endpoint_hint')}
                        </p>
                    </div>

                    {/*
                    Beside the MCP address rather than on a page of its own:
                    somebody who has just made a token is exactly who needs to
                    know where to point it. The two doors take different
                    credentials — this is the one the token is for.
                */}
                    <div className="grid gap-2">
                        <Label htmlFor="api-endpoint">
                            {t('settings.api_tokens.api_endpoint')}
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="api-endpoint"
                                value={apiEndpoint}
                                readOnly
                                className="font-mono text-xs"
                            />
                            <CopyButton
                                value={apiEndpoint}
                                label={t(
                                    'settings.api_tokens.copy_api_endpoint',
                                )}
                            />
                        </div>
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
                            <Label htmlFor="token-name">
                                {t('settings.api_tokens.new_token')}
                            </Label>
                            <div className="flex items-start gap-2">
                                <span className="grid flex-1 gap-1">
                                    <Input
                                        id="token-name"
                                        name="name"
                                        maxLength={60}
                                        required
                                        placeholder={t(
                                            'settings.api_tokens.name_placeholder',
                                        )}
                                    />
                                    <InputError message={errors.name} />
                                </span>
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    {t('settings.api_tokens.create')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                {tokens.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <Bot className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">
                            {t('settings.api_tokens.empty')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('settings.api_tokens.empty_hint')}
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
                                        {token.token ??
                                            t('settings.api_tokens.hidden')}
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
                                        ? t('settings.api_tokens.revoked')
                                        : token.lastUsedAt
                                          ? t('settings.api_tokens.last_used', {
                                                moment: formats.dateTime.format(
                                                    new Date(token.lastUsedAt),
                                                ),
                                            })
                                          : t('settings.api_tokens.never_used')}
                                </span>

                                {token.token && (
                                    <CopyButton
                                        value={token.token}
                                        label={t(
                                            'settings.api_tokens.copy_token',
                                        )}
                                    />
                                )}

                                {!token.revokedAt && (
                                    <button
                                        type="button"
                                        onClick={() => setRevoking(token)}
                                        aria-label={t(
                                            'settings.api_tokens.revoke_named',
                                            { name: token.name },
                                        )}
                                        title={t('settings.api_tokens.revoke')}
                                        className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </SettingsSection>

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
                            {t('settings.api_tokens.revoke_question', {
                                name: revoking?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('settings.api_tokens.revoke_explanation')}
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
                                if (revoking) {
                                    router.delete(destroy.url(revoking.id), {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {t('settings.api_tokens.revoke')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
