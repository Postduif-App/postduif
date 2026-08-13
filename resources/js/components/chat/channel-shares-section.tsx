import { Building2, Check, Clock, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';
import { index, store } from '@/routes/chat/channels/shares';
import { destroy } from '@/routes/chat/shares';
import type { ActiveChannel, ChannelShare, ChatWorkspace } from '@/types/chat';

/**
 * Which other workspaces this channel stands open to, and the form to open it
 * to one more.
 *
 * The host's half of a shared channel. The other side's half is not here and
 * cannot be: accepting, and putting their own people in, are theirs to do from
 * their own workspace — which is the point of the feature rather than a gap in
 * this panel.
 *
 * Fetched when the tab is opened rather than sent with the page, the same way
 * the webhook panel beside it works. Most channels are shared with nobody, and
 * a list that is empty for nearly every channel is not worth carrying in every
 * page load of the chat screen.
 */
export function ChannelSharesSection({
    workspace,
    channel,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}) {
    const { t } = useTranslate();

    const [shares, setShares] = useState<ChannelShare[] | null>(null);
    const [slug, setSlug] = useState('');
    const [canPost, setCanPost] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;

        const load = async () => {
            const response = await fetch(index.url([workspace, channel]), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok || cancelled) {
                return;
            }

            const payload = (await response.json()) as {
                shares: ChannelShare[];
            };

            if (!cancelled) {
                setShares(payload.shares);
            }
        };

        void load();

        // The tab can be closed while the list is still on its way, and a
        // setState afterwards would be a write to a component that is gone.
        return () => {
            cancelled = true;
        };
    }, [workspace, channel]);

    const offer = async () => {
        setSaving(true);
        setError(null);

        const response = await fetch(store.url([workspace, channel]), {
            method: 'post',
            headers: mutatingHeaders(),
            body: JSON.stringify({ workspace: slug.trim(), can_post: canPost }),
        });

        setSaving(false);

        /*
         * Every refusal here is one the person could not have seen coming from
         * the form — that workspace does not exist, does not accept shares, or
         * is this one. So the server's own sentence is shown rather than a
         * generic failure: it is the only thing that says which of those it was.
         */
        if (!response.ok) {
            const payload = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            setError(payload.errors?.workspace?.[0] ?? payload.message ?? null);

            return;
        }

        const { share } = (await response.json()) as { share: ChannelShare };

        setShares((current) => [
            ...(current ?? []).filter((row) => row.id !== share.id),
            share,
        ]);
        setSlug('');
    };

    const revoke = async (share: ChannelShare) => {
        const response = await fetch(destroy.url([workspace, share]), {
            method: 'delete',
            headers: mutatingHeaders(),
        });

        if (!response.ok) {
            return;
        }

        const payload = (await response.json()) as { share: ChannelShare };

        setShares((current) =>
            (current ?? []).map((row) =>
                row.id === payload.share.id ? payload.share : row,
            ),
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-medium">
                    {t('panels.shares.heading')}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {t('panels.shares.explanation')}
                </p>
            </div>

            {shares === null ? (
                <Spinner className="size-4" />
            ) : shares.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('panels.shares.none')}
                </p>
            ) : (
                <ul className="flex flex-col gap-1.5">
                    {shares.map((share) => (
                        <li
                            key={share.id}
                            className="flex items-center gap-2 rounded border px-2 py-1.5"
                        >
                            <Building2 className="size-3.5 shrink-0 text-muted-foreground" />

                            <span className="min-w-0 flex-1 truncate text-sm">
                                {share.workspace.name}
                            </span>

                            {/*
                                What the arrangement is, in a word. The state
                                first, because "wacht op antwoord" changes what
                                the line beside it means: a read-only share
                                nobody has accepted grants nothing at all.
                            */}
                            <ShareState state={share.state} />

                            <span className="shrink-0 text-xs text-muted-foreground">
                                {share.canPost
                                    ? t('panels.shares.may_post')
                                    : t('panels.shares.may_read')}
                            </span>

                            {share.state !== 'revoked' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="shrink-0"
                                    onClick={() => void revoke(share)}
                                >
                                    {t('panels.shares.revoke')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex flex-col gap-2 border-t pt-4">
                <Label htmlFor="share-workspace">
                    {t('panels.shares.slug_label')}
                </Label>
                <p className="text-xs text-muted-foreground">
                    {t('panels.shares.slug_hint')}
                </p>

                <Input
                    id="share-workspace"
                    value={slug}
                    onChange={(event) => setSlug(event.target.value)}
                    placeholder={t('panels.shares.slug_placeholder')}
                />

                {error !== null && (
                    <p className="text-xs text-destructive">{error}</p>
                )}

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={canPost}
                        onCheckedChange={(checked) =>
                            setCanPost(checked === true)
                        }
                    />
                    {t('panels.shares.can_post_label')}
                </label>

                <Button
                    className="self-start"
                    disabled={saving || slug.trim() === ''}
                    onClick={() => void offer()}
                >
                    {saving ? (
                        <Spinner className="size-4" />
                    ) : (
                        <Plus className="size-4" />
                    )}
                    {t('panels.shares.offer')}
                </Button>
            </div>
        </div>
    );
}

/** Where a share stands, as one quiet chip. */
function ShareState({ state }: { state: ChannelShare['state'] }) {
    const { t } = useTranslate();

    if (state === 'accepted') {
        return (
            <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                <Check className="size-3" />
                {t('panels.shares.state.accepted')}
            </span>
        );
    }

    if (state === 'pending') {
        return (
            <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                <Clock className="size-3" />
                {t('panels.shares.state.pending')}
            </span>
        );
    }

    return (
        <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
            <X className="size-3" />
            {state === 'declined'
                ? t('panels.shares.state.declined')
                : t('panels.shares.state.revoked')}
        </span>
    );
}
