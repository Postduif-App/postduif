import { router } from '@inertiajs/react';
import { Check, Crown, LogOut, UserPlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { GuestBadge } from '@/components/chat/guest-badge';
import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, index, remove, store } from '@/routes/chat/channels/members';
import type { ActiveChannel, ChannelMember, ChatWorkspace } from '@/types/chat';

interface ChannelMembersDialogProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    currentUserId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const DEBOUNCE_MS = 200;

function MemberRow({
    member,
    trailing,
    onClick,
    selected,
}: {
    member: ChannelMember;
    trailing?: React.ReactNode;
    onClick?: () => void;
    selected?: boolean;
}) {
    const getInitials = useInitials();
    const Wrapper = onClick ? 'button' : 'div';

    return (
        <Wrapper
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left',
                onClick && 'hover:bg-muted',
                selected && 'bg-primary/10',
            )}
        >
            <span className="relative shrink-0">
                {/* The face when there is one, initials when there is not. */}
                {member.avatarUrl ? (
                    <img
                        src={member.avatarUrl}
                        alt=""
                        className="size-7 rounded object-cover"
                    />
                ) : (
                    <span className="flex size-7 items-center justify-center rounded bg-muted text-[11px] font-semibold">
                        {getInitials(member.name)}
                    </span>
                )}
                <AvailabilityDot
                    availability={member.availability}
                    className="absolute -right-0.5 -bottom-0.5"
                />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5">
                    <span className="truncate text-sm font-medium">
                        {member.name}
                    </span>
                    {member.isGuest && <GuestBadge />}
                    <MemberStatus
                        emoji={member.statusEmoji}
                        text={member.statusText}
                    />
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    @{member.username}
                    {member.statusText && ` · ${member.statusText}`}
                </span>
            </span>
            {trailing}
        </Wrapper>
    );
}

export function ChannelMembersDialog({
    workspace,
    channel,
    currentUserId,
    open,
    onOpenChange,
}: ChannelMembersDialogProps) {
    const { t } = useTranslate();
    const [query, setQuery] = useState('');
    const [candidates, setCandidates] = useState<ChannelMember[]>([]);
    const [selected, setSelected] = useState<number[]>([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [pendingRemoval, setPendingRemoval] = useState<ChannelMember | null>(
        null,
    );
    // Bumped after every membership change so the candidate list refetches.
    // The channel's own members arrive as props and update themselves, but the
    // people who could still be added come from a separate request that has no
    // reason to know anything happened.
    const [refreshToken, setRefreshToken] = useState(0);

    const canInvite = channel.canAddMembers;

    useEffect(() => {
        if (!open || !canInvite) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    index.url(
                        { workspace: workspace.slug, channel: channel.id },
                        { query: { q: query.trim() } },
                    ),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();
                setCandidates(payload.candidates ?? []);
            } catch (error) {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setCandidates([]);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, canInvite, query, workspace.slug, channel.id, refreshToken]);

    const submit = () => {
        setSaving(true);
        router.post(
            store.url({ workspace: workspace.slug, channel: channel.id }),
            { user_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setQuery('');
                    setRefreshToken((current) => current + 1);
                    onOpenChange(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('channels.members.title', {
                            channel:
                                channel.type === 'dm'
                                    ? channel.label
                                    : `#${channel.label}`,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {channel.type === 'private'
                            ? t('channels.members.private_note')
                            : t('channels.members.public_note')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-5">
                    <div className="grid gap-1.5">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('channels.members.in_channel', {
                                count: channel.members.length,
                            })}
                        </p>
                        {/*
                            A plain overflow container, not Radix's ScrollArea:
                            that one sizes its viewport with h-full, which
                            resolves to auto inside a max-h-only parent, so the
                            list spills over whatever sits below it.
                        */}
                        <div className="-mx-1 max-h-40 overflow-y-auto px-1">
                            {channel.members.map((member) => (
                                <MemberRow
                                    key={member.id}
                                    member={member}
                                    trailing={
                                        member.id === channel.createdBy ? (
                                            <span className="flex shrink-0 items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                <Crown className="size-3" />
                                                {t('channels.members.owner')}
                                            </span>
                                        ) : channel.canAddMembers ? (
                                            <button
                                                type="button"
                                                aria-label={t(
                                                    'channels.members.remove',
                                                    { name: member.name },
                                                )}
                                                title={t(
                                                    'channels.members.remove_title',
                                                )}
                                                onClick={() =>
                                                    setPendingRemoval(member)
                                                }
                                                className="shrink-0 rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        ) : undefined
                                    }
                                />
                            ))}
                        </div>
                    </div>

                    {canInvite && (
                        <div className="grid gap-1.5 border-t pt-4">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('channels.members.add')}
                            </p>
                            <Input
                                value={query}
                                placeholder={t(
                                    'channels.members.search_placeholder',
                                )}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                            <div className="-mx-1 max-h-44 overflow-y-auto px-1">
                                {candidates.map((member) => (
                                    <MemberRow
                                        key={member.id}
                                        member={member}
                                        selected={selected.includes(member.id)}
                                        onClick={() =>
                                            setSelected((current) =>
                                                current.includes(member.id)
                                                    ? current.filter(
                                                          (id) =>
                                                              id !== member.id,
                                                      )
                                                    : [...current, member.id],
                                            )
                                        }
                                        trailing={
                                            selected.includes(member.id) ? (
                                                <Check className="size-4 shrink-0 text-primary" />
                                            ) : undefined
                                        }
                                    />
                                ))}
                                {!loading && candidates.length === 0 && (
                                    <p className="px-2 py-2 text-sm text-muted-foreground">
                                        {query.trim() === ''
                                            ? t('channels.members.all_in')
                                            : t('channels.members.none_found')}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                <DialogFooter className="sm:justify-between">
                    {channel.canLeave ? (
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-destructive hover:text-destructive"
                            onClick={() =>
                                router.delete(
                                    destroy.url({
                                        workspace: workspace.slug,
                                        channel: channel.id,
                                    }),
                                )
                            }
                        >
                            <LogOut className="size-4" />
                            {t('channels.members.leave')}
                        </Button>
                    ) : channel.createdBy === currentUserId ? (
                        <p className="max-w-[15rem] text-xs text-muted-foreground">
                            {t('channels.members.cannot_leave')}
                        </p>
                    ) : (
                        <span />
                    )}

                    {canInvite && (
                        <Button
                            type="button"
                            disabled={selected.length === 0 || saving}
                            onClick={submit}
                        >
                            {saving ? (
                                <Spinner />
                            ) : (
                                <UserPlus className="size-4" />
                            )}
                            {selected.length === 0
                                ? t('channels.members.add')
                                : t('channels.members.add_selected', {
                                      count: selected.length,
                                  })}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>

            <AlertDialog
                open={pendingRemoval !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setPendingRemoval(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-sm">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('channels.members.remove_question', {
                                name: pendingRemoval?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t(
                                channel.type === 'private'
                                    ? 'channels.members.remove_private'
                                    : 'channels.members.remove_public',
                                {
                                    name: pendingRemoval?.name ?? '',
                                    channel: channel.label,
                                },
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('channels.actions.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                const member = pendingRemoval;

                                if (!member) {
                                    return;
                                }

                                router.delete(
                                    remove.url({
                                        workspace: workspace.slug,
                                        channel: channel.id,
                                        user: member.id,
                                    }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            setRefreshToken(
                                                (current) => current + 1,
                                            ),
                                        onFinish: () => setPendingRemoval(null),
                                    },
                                );
                            }}
                        >
                            {t('channels.actions.remove')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Dialog>
    );
}
