import { Bot, Check, Copy, Eye, EyeOff, Plus, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { mutatingHeaders } from '@/lib/csrf';
import {
    destroy,
    index,
    regenerate,
    store,
} from '@/routes/chat/channels/webhooks';
import type {
    ActiveChannel,
    ChannelWebhook,
    ChatWorkspace,
} from '@/types/chat';

const DATE_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'short',
});

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
    const [shown, setShown] = useState(false);
    const [copied, setCopied] = useState(false);

    // Made before the token was kept, so there is nothing to show. A new one is
    // the only honest answer — and it says so rather than leaving a dead row.
    if (url === null) {
        return (
            <div className="flex items-center justify-between gap-2 rounded bg-muted/50 px-2 py-1.5">
                <p className="text-xs text-muted-foreground">
                    De URL van deze webhook is niet meer op te vragen.
                </p>
                <Button
                    size="sm"
                    variant="ghost"
                    className="shrink-0"
                    onClick={onRegenerate}
                >
                    <RefreshCw className="size-3.5" />
                    Nieuwe URL
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
                    {shown ? 'Verbergen' : 'Toon URL'}
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
                    {copied ? 'Gekopieerd' : 'Kopiëren'}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto shrink-0 text-muted-foreground"
                    title="De huidige URL stopt dan met werken"
                    onClick={onRegenerate}
                >
                    <RefreshCw className="size-3.5" />
                    Vervangen
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
    const [webhooks, setWebhooks] = useState<ChannelWebhook[]>([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [botName, setBotName] = useState('');
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
                body: JSON.stringify({ name, bot_name: botName }),
            });

            if (!response.ok) {
                setError('Aanmaken is niet gelukt. Controleer de namen.');

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
                    <h3 className="text-sm font-medium">Webhooks</h3>
                    <p className="text-xs text-muted-foreground">
                        Laat iets buiten Pcom in dit kanaal posten, onder een
                        eigen naam en herkenbaar als bot.
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
                        Toevoegen
                    </Button>
                )}
            </div>

            {adding && (
                <div className="flex flex-col gap-3 rounded-lg border p-3">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="webhook-name">Naam</Label>
                        <Input
                            id="webhook-name"
                            value={name}
                            maxLength={80}
                            placeholder="CI"
                            onChange={(event) => setName(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Waar deze webhook voor is. Alleen jullie zien dit.
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="webhook-bot-name">Postte als</Label>
                        <Input
                            id="webhook-bot-name"
                            value={botName}
                            maxLength={80}
                            placeholder="Buildbot"
                            onChange={(event) => setBotName(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            De naam bij de berichten. Er staat altijd BOT naast.
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
                            Annuleren
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
                            Aanmaken
                        </Button>
                    </div>
                </div>
            )}

            {loading ? (
                <Spinner />
            ) : webhooks.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Nog geen webhooks in dit kanaal.
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
                                        Postte als {webhook.botName} ·{' '}
                                        {webhook.revokedAt
                                            ? 'ingetrokken'
                                            : webhook.lastUsedAt
                                              ? `laatst gebruikt ${DATE_FORMAT.format(new Date(webhook.lastUsedAt))}`
                                              : 'nog niet gebruikt'}
                                    </p>
                                </div>
                                {!webhook.revokedAt && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="shrink-0 text-destructive"
                                        onClick={() => void revoke(webhook)}
                                    >
                                        Intrekken
                                    </Button>
                                )}
                            </div>

                            {/* A revoked webhook has no working URL, so it gets
                                no row of buttons offering one. */}
                            {!webhook.revokedAt && (
                                <WebhookUrl
                                    url={webhook.url}
                                    onRegenerate={() => void renew(webhook)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Stuur een POST naar de URL met een JSON-body zoals{' '}
                <code className="font-mono">{'{"text": "Hallo"}'}</code>.
            </p>
        </div>
    );
}
