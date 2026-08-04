import { Bot, Check, Copy, Eye, EyeOff, Plus, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PayloadPaths } from '@/components/chat/payload-paths';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';
import {
    destroy,
    index,
    regenerate,
    store,
    update,
} from '@/routes/chat/channels/webhooks';
import type {
    ActiveChannel,
    ChannelWebhook,
    ChatWorkspace,
} from '@/types/chat';

/**
 * The URL of one webhook, shown on request.
 *
 * Hidden until asked for, even though it can always be fetched again: this
 * dialog is opened to change a posting policy as often as to set an
 * integration up, and a list of live credentials on screen is not something to
 * hand out to whoever is looking over a shoulder.
 */
function WebhookUrl({
    url,
    onRegenerate,
}: {
    url: string | null;
    onRegenerate: () => void;
}) {
    const { t } = useTranslate();

    const [shown, setShown] = useState(false);
    const [copied, setCopied] = useState(false);

    // Made before the token was kept, so there is nothing to show. A new one is
    // the only honest answer — and it says so rather than leaving a dead row.
    if (url === null) {
        return (
            <div className="flex items-center justify-between gap-2 rounded bg-muted/50 px-2 py-1.5">
                <p className="text-xs text-muted-foreground">
                    {t('panels.webhooks.url_gone')}
                </p>
                <Button
                    size="sm"
                    variant="ghost"
                    className="shrink-0"
                    onClick={onRegenerate}
                >
                    <RefreshCw className="size-3.5" />
                    {t('panels.webhooks.new_url')}
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-center gap-2">
                <Button
                    size="sm"
                    variant="ghost"
                    className="shrink-0"
                    onClick={() => setShown((current) => !current)}
                >
                    {shown ? (
                        <EyeOff className="size-3.5" />
                    ) : (
                        <Eye className="size-3.5" />
                    )}
                    {shown
                        ? t('panels.webhooks.hide_url')
                        : t('panels.webhooks.show_url')}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="shrink-0"
                    onClick={() => {
                        void navigator.clipboard.writeText(url);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    }}
                >
                    {copied ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copied
                        ? t('panels.webhooks.copied')
                        : t('panels.webhooks.copy')}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto shrink-0 text-muted-foreground"
                    title={t('panels.webhooks.replace_hint')}
                    onClick={onRegenerate}
                >
                    <RefreshCw className="size-3.5" />
                    {t('panels.webhooks.replace')}
                </Button>
            </div>

            {shown && (
                /*
                    Wrapped rather than truncated: somebody checking a URL
                    should see all of it. break-all also gives the string the
                    break opportunity it otherwise lacks, so it can never push
                    the dialog wider than itself.
                */
                <code className="block rounded bg-muted px-2 py-1.5 font-mono text-xs break-all">
                    {url}
                </code>
            )}
        </div>
    );
}

export function ChannelWebhooksSection({
    workspace,
    channel,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}) {
    const formats = useFormats();
    const { t } = useTranslate();

    const [webhooks, setWebhooks] = useState<ChannelWebhook[]>([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [botName, setBotName] = useState('');
    const [bodyPath, setBodyPath] = useState('');
    const [error, setError] = useState<string | null>(null);

    const target = { workspace: workspace.slug, channel: channel.id };

    // The list is fetched rather than passed in as a prop: webhooks are a
    // detail of the settings dialog, and putting them in every chat page render
    // would mean sending posting URLs along with a conversation.
    //
    // Nothing is set synchronously in here — loading starts out true, and every
    // write happens after the request resolves. A cancelled flag rather than
    // just aborting, so a response already in flight cannot land in a component
    // that has moved to another channel.
    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();

        void (async () => {
            try {
                const response = await fetch(
                    index.url({
                        workspace: workspace.slug,
                        channel: channel.id,
                    }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();

                if (!cancelled) {
                    setWebhooks(payload.webhooks ?? []);
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [workspace.slug, channel.id]);

    /** Swap one row for the version the server just returned. */
    const replace = (updated: ChannelWebhook) =>
        setWebhooks((current) =>
            current.map((entry) => (entry.id === updated.id ? updated : entry)),
        );

    const create = async () => {
        setSaving(true);
        setError(null);

        try {
            const response = await fetch(store.url(target), {
                method: 'POST',
                headers: mutatingHeaders(),
                body: JSON.stringify({
                    name,
                    bot_name: botName,
                    body_path: bodyPath.trim() || null,
                }),
            });

            if (!response.ok) {
                setError(t('panels.webhooks.create_failed'));

                return;
            }

            const payload = await response.json();

            setWebhooks((current) => [payload.webhook, ...current]);
            setAdding(false);
            setName('');
            setBotName('');
        } finally {
            setSaving(false);
        }
    };

    const renew = async (webhook: ChannelWebhook) => {
        const response = await fetch(
            regenerate.url({ ...target, webhook: webhook.id }),
            { method: 'POST', headers: mutatingHeaders() },
        );

        if (response.ok) {
            replace((await response.json()).webhook);
        }
    };

    /**
     * Point a webhook at a different part of the payload.
     *
     * Saved on blur rather than behind a button: it is one field, and a Save
     * next to a single input is a step that only exists to be forgotten. A
     * value that has not changed does not go anywhere.
     */
    const retarget = async (webhook: ChannelWebhook, path: string) => {
        const wanted = path.trim() === '' ? null : path.trim();

        if (wanted === webhook.bodyPath) {
            return;
        }

        const response = await fetch(
            update.url({ ...target, webhook: webhook.id }),
            {
                method: 'PATCH',
                headers: mutatingHeaders(),
                body: JSON.stringify({
                    name: webhook.name,
                    bot_name: webhook.botName,
                    body_path: wanted,
                }),
            },
        );

        if (response.ok) {
            replace((await response.json()).webhook);
        } else {
            setError(t('panels.webhooks.path_failed'));
        }
    };

    const revoke = async (webhook: ChannelWebhook) => {
        const response = await fetch(
            destroy.url({ ...target, webhook: webhook.id }),
            { method: 'DELETE', headers: mutatingHeaders() },
        );

        if (response.ok) {
            replace((await response.json()).webhook);
        }
    };

    return (
        // min-w-0 is load-bearing: the dialog lays its children out in a grid,
        // where an item's minimum width is its content. The webhook URL has no
        // break opportunity, so without this it refuses to shrink and stretches
        // the whole dialog — radio buttons and all — past its own edge.
        <div className="flex min-w-0 flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-medium">
                        {t('panels.webhooks.heading')}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {t('panels.webhooks.explanation')}
                    </p>
                </div>
                {!adding && (
                    <Button
                        size="sm"
                        variant="outline"
                        className="shrink-0"
                        onClick={() => setAdding(true)}
                    >
                        <Plus className="size-3.5" />
                        {t('panels.webhooks.add')}
                    </Button>
                )}
            </div>

            {adding && (
                <div className="flex flex-col gap-3 rounded-lg border p-3">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="webhook-name">
                            {t('panels.webhooks.name_label')}
                        </Label>
                        <Input
                            id="webhook-name"
                            value={name}
                            maxLength={80}
                            placeholder="CI"
                            onChange={(event) => setName(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('panels.webhooks.name_hint')}
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="webhook-bot-name">
                            {t('panels.webhooks.bot_name_label')}
                        </Label>
                        <Input
                            id="webhook-bot-name"
                            value={botName}
                            maxLength={80}
                            placeholder="Buildbot"
                            onChange={(event) => setBotName(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('panels.webhooks.bot_name_hint')}
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="webhook-body-path">
                            {t('panels.webhooks.body_path_label')}
                        </Label>
                        <Input
                            id="webhook-body-path"
                            value={bodyPath}
                            maxLength={200}
                            placeholder="text"
                            onChange={(event) =>
                                setBodyPath(event.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('panels.webhooks.body_path_hint_lead')}{' '}
                            <code className="font-mono">
                                {'{"text": "..."}'}
                            </code>{' '}
                            {t('panels.webhooks.body_path_hint_middle')}{' '}
                            <code className="font-mono">{'issue.title'}</code>
                            {t('panels.webhooks.body_path_hint_or')}{' '}
                            <code className="font-mono">
                                {'commits.0.message'}
                            </code>{' '}
                            {t('panels.webhooks.body_path_hint_tail')}
                        </p>
                    </div>

                    {error && (
                        <p className="text-xs text-destructive">{error}</p>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setAdding(false);
                                setError(null);
                            }}
                        >
                            {t('panels.webhooks.cancel')}
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                saving ||
                                name.trim() === '' ||
                                botName.trim() === ''
                            }
                            onClick={() => void create()}
                        >
                            {saving && <Spinner />}
                            {t('panels.webhooks.create')}
                        </Button>
                    </div>
                </div>
            )}

            {loading ? (
                <Spinner />
            ) : webhooks.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('panels.webhooks.none')}
                </p>
            ) : (
                // No scroll of its own: the dialog already scrolls, and a second
                // scroll area inside it clips the last row behind the footer.
                <ul className="flex flex-col gap-1">
                    {webhooks.map((webhook) => (
                        <li
                            key={webhook.id}
                            className="flex flex-col gap-2 rounded-md border px-3 py-2"
                        >
                            <div className="flex items-center gap-3">
                                <Bot className="size-4 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {webhook.name}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {t('panels.webhooks.posts_as', {
                                            name: webhook.botName,
                                        })}
                                        {' · '}
                                        {webhook.revokedAt
                                            ? t('panels.webhooks.revoked')
                                            : webhook.lastUsedAt
                                              ? t('panels.webhooks.last_used', {
                                                    at: formats.shortDateTime.format(
                                                        new Date(
                                                            webhook.lastUsedAt,
                                                        ),
                                                    ),
                                                })
                                              : t('panels.webhooks.never_used')}
                                    </p>
                                </div>
                                {!webhook.revokedAt && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="shrink-0 text-destructive"
                                        onClick={() => void revoke(webhook)}
                                    >
                                        {t('panels.webhooks.revoke')}
                                    </Button>
                                )}
                            </div>

                            {/* A revoked webhook has no working URL, so it gets
                                no row of buttons offering one. */}
                            {!webhook.revokedAt && (
                                <>
                                    <WebhookUrl
                                        url={webhook.url}
                                        onRegenerate={() => void renew(webhook)}
                                    />

                                    {/*
                                        Editable here rather than only at
                                        creation: which field carries the text
                                        is the thing you get wrong first and
                                        find out about later.
                                    */}
                                    <div className="flex items-center gap-2">
                                        <Label
                                            htmlFor={`webhook-path-${webhook.id}`}
                                            className="shrink-0 text-xs text-muted-foreground"
                                        >
                                            {t('panels.webhooks.path_label')}
                                        </Label>
                                        <Input
                                            id={`webhook-path-${webhook.id}`}
                                            // Keyed on the stored path so
                                            // picking one from the sample below
                                            // remounts the field with it, which
                                            // an uncontrolled input would
                                            // otherwise ignore.
                                            key={webhook.bodyPath ?? ''}
                                            defaultValue={
                                                webhook.bodyPath ?? ''
                                            }
                                            maxLength={200}
                                            placeholder="text"
                                            className="h-8 font-mono text-xs"
                                            onBlur={(event) =>
                                                void retarget(
                                                    webhook,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    {/*
                                        Writing a path against a payload you
                                        cannot see is guessing. Here it is, with
                                        every usable path in it clickable.
                                    */}
                                    {webhook.lastPayload && (
                                        <PayloadPaths
                                            payload={webhook.lastPayload}
                                            onPick={(path) =>
                                                void retarget(webhook, path)
                                            }
                                        />
                                    )}
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                {t('panels.webhooks.footer_lead')}{' '}
                <code className="font-mono">{'{"text": "Hallo"}'}</code>{' '}
                {t('panels.webhooks.footer_tail')}
            </p>
        </div>
    );
}
