import { router } from '@inertiajs/react';
import { Building2, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { mutatingHeaders } from '@/lib/csrf';
import { update } from '@/routes/chat/shares';
import { index, store } from '@/routes/chat/shares/members';
import type { ChannelShareInvitation, ChatWorkspace } from '@/types/chat';

/** Somebody in this workspace who could be put into the shared channel. */
interface Candidate {
    id: number;
    name: string;
    username: string;
    avatarUrl: string | null;
    /** Already in the channel — shown, and not offered again. */
    alreadyIn: boolean;
}

/**
 * The invited workspace's side of a shared channel, in their own sidebar.
 *
 * Two jobs in one list, because they are two steps of the same thing: answer
 * what another organisation has offered, and then put your own colleagues into
 * what you said yes to. The second step has nowhere else it could live — an
 * accepted share is otherwise invisible from this side, and the channel it
 * opens would sit there with nobody in it.
 *
 * Only drawn for somebody who may answer; for everybody else the list arrives
 * empty and this renders nothing.
 */
export function SharedChannelsPanel({
    workspace,
    invitations,
}: {
    workspace: ChatWorkspace;
    invitations: ChannelShareInvitation[];
}) {
    const { t } = useTranslate();

    const [adding, setAdding] = useState<ChannelShareInvitation | null>(null);

    if (invitations.length === 0) {
        return null;
    }

    const answer = (share: ChannelShareInvitation, accepted: boolean) => {
        /*
         * Through Inertia rather than a bare fetch, unlike the host's panel:
         * saying yes makes a channel appear in this very sidebar, and a reload
         * of the page props is the only thing that puts it there without this
         * component having to know how a channel row is built.
         */
        router.patch(
            update.url([workspace, share]),
            { accepted },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <div className="flex flex-col gap-1 px-2 py-2">
                <h2 className="px-1 text-xs font-medium tracking-wide text-sidebar-foreground/60 uppercase">
                    {t('sidebar.shares.heading')}
                </h2>

                {invitations.map((share) => (
                    <div
                        key={share.id}
                        className="flex flex-col gap-1.5 rounded-md border border-sidebar-border px-2 py-2"
                    >
                        <div className="flex items-center gap-1.5">
                            <Building2 className="size-3.5 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate text-sm">
                                {share.channelName}
                            </span>
                        </div>

                        {/*
                            Who is asking, always — for an unanswered offer it is
                            the whole of what the decision turns on, and for an
                            accepted one it is what stops somebody adding four
                            colleagues to a room they think is their own.
                        */}
                        <p className="text-xs text-muted-foreground">
                            {t('sidebar.shares.from', {
                                workspace: share.workspaceName,
                            })}
                        </p>

                        {share.accepted ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="self-start"
                                onClick={() => setAdding(share)}
                            >
                                <UserPlus className="size-3.5" />
                                {t('sidebar.shares.add_colleagues')}
                            </Button>
                        ) : (
                            <div className="flex flex-col gap-1.5">
                                <p className="text-xs text-muted-foreground">
                                    {share.canPost
                                        ? t('sidebar.shares.may_post')
                                        : t('sidebar.shares.may_read')}
                                </p>

                                <div className="flex gap-1.5">
                                    <Button
                                        size="sm"
                                        onClick={() => answer(share, true)}
                                    >
                                        {t('sidebar.shares.accept')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => answer(share, false)}
                                    >
                                        {t('sidebar.shares.decline')}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {adding !== null && (
                <AddColleaguesDialog
                    workspace={workspace}
                    share={adding}
                    onClose={() => setAdding(null)}
                />
            )}
        </>
    );
}

/**
 * Picking colleagues to put into a shared channel.
 *
 * Its own list rather than the workspace member panel's: that one is not always
 * sent — a workspace can switch it off — and this dialog is the only way into a
 * shared channel from this side. A picker that is empty because of an unrelated
 * setting would be a dead end with no explanation.
 */
function AddColleaguesDialog({
    workspace,
    share,
    onClose,
}: {
    workspace: ChatWorkspace;
    share: ChannelShareInvitation;
    onClose: () => void;
}) {
    const { t } = useTranslate();

    const [candidates, setCandidates] = useState<Candidate[] | null>(null);
    const [picked, setPicked] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let cancelled = false;

        const load = async () => {
            const response = await fetch(index.url([workspace, share]), {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const payload = (await response.json()) as {
                candidates: Candidate[];
            };

            if (!cancelled) {
                setCandidates(payload.candidates);
            }
        };

        void load();

        return () => {
            cancelled = true;
        };
    }, [workspace, share]);

    const add = async () => {
        setSaving(true);

        const response = await fetch(store.url([workspace, share]), {
            method: 'post',
            headers: mutatingHeaders(),
            body: JSON.stringify({ members: picked }),
        });

        setSaving(false);

        if (!response.ok) {
            return;
        }

        onClose();

        // Their sidebar does not change, but theirs does — and the channel's
        // member list on this page is now out of date either way.
        router.reload({ only: ['channels'] });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('sidebar.shares.add_title')}</DialogTitle>
                    <DialogDescription>
                        {t('sidebar.shares.add_description', {
                            channel: share.channelName ?? '',
                            workspace: share.workspaceName,
                        })}
                    </DialogDescription>
                </DialogHeader>

                {candidates === null ? (
                    <Spinner className="size-4" />
                ) : (
                    <ul className="flex max-h-72 flex-col gap-0.5 overflow-y-auto">
                        {candidates.map((candidate) => (
                            <li key={candidate.id}>
                                <label
                                    className={cnRow(candidate.alreadyIn)}
                                    aria-disabled={candidate.alreadyIn}
                                >
                                    <Checkbox
                                        disabled={candidate.alreadyIn}
                                        checked={
                                            candidate.alreadyIn ||
                                            picked.includes(candidate.id)
                                        }
                                        onCheckedChange={(checked) =>
                                            setPicked((current) =>
                                                checked === true
                                                    ? [...current, candidate.id]
                                                    : current.filter(
                                                          (id) =>
                                                              id !==
                                                              candidate.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="min-w-0 flex-1 truncate">
                                        {candidate.name}
                                    </span>
                                    {candidate.alreadyIn && (
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {t('sidebar.shares.already_in')}
                                        </span>
                                    )}
                                </label>
                            </li>
                        ))}
                    </ul>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('channels.actions.cancel')}
                    </Button>
                    <Button
                        disabled={saving || picked.length === 0}
                        onClick={() => void add()}
                    >
                        {saving && <Spinner className="size-4" />}
                        {t('sidebar.shares.add_confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** One row of the picker, dimmed for somebody who is already in. */
function cnRow(alreadyIn: boolean): string {
    return [
        'flex items-center gap-2 rounded px-2 py-1.5 text-sm',
        alreadyIn ? 'opacity-60' : 'hover:bg-muted/60',
    ].join(' ');
}
