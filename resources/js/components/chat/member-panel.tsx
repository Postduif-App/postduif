import { Link } from '@inertiajs/react';
import { X } from 'lucide-react';

import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { useWorkspacePresence } from '@/hooks/use-workspace-presence';
import { cn } from '@/lib/utils';
import { show as memberProfile } from '@/routes/chat/members';
import type { ChannelMember } from '@/types/chat';

/**
 * Who is in the workspace, beside the conversation.
 *
 * A sibling of the channel sidebar rather than something inside the
 * conversation: it belongs to the workspace, not to whatever is open, so it has
 * no business being torn down and rebuilt every time somebody switches channel.
 */
export function MemberPanel({
    members,
    currentUserId,
    workspaceSlug,
    onClose,
}: {
    members: ChannelMember[];
    currentUserId: number;
    workspaceSlug: string;
    onClose: () => void;
}) {
    const getInitials = useInitials();
    const { t } = useTranslate();
    const present = useWorkspacePresence(workspaceSlug);

    if (members.length === 0) {
        return null;
    }

    /*
     * Whoever is here first, then by name. Sorted in the browser rather than on
     * the server because presence changes by the second and the page does not:
     * a member closing their laptop would otherwise leave the list claiming
     * they are around until somebody reloads.
     */
    const sorted = [...members].sort((a, b) => {
        const here = Number(present.has(b.id)) - Number(present.has(a.id));

        return here !== 0 ? here : a.name.localeCompare(b.name, 'nl');
    });

    return (
        <aside className="hidden w-60 shrink-0 flex-col border-l bg-sidebar lg:flex">
            {/*
                Closing it away entirely rather than shrinking to a rail of
                icons, and the way back in is the members button in the channel
                header — the same shape as the sidebar on the other side, which
                is the one people already know.
            */}
            <div className="flex items-center gap-2 border-b px-3 py-3 text-sm font-medium text-sidebar-foreground/80">
                <span>{t('chat_ui.members.heading')}</span>
                <span className="text-xs text-muted-foreground">
                    {members.length}
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    title={t('conversation.members.close')}
                    aria-label={t('conversation.members.close')}
                    className="ml-auto text-muted-foreground transition-colors hover:text-sidebar-foreground"
                >
                    <X className="size-4" />
                </button>
            </div>

            <div className="flex-1 overflow-y-auto p-2">
                {sorted.map((member) => (
                    <div
                        key={member.id}
                        className="flex items-center gap-2.5 rounded-md px-2 py-1.5"
                    >
                        <span className="relative shrink-0">
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
                            {/*
                                    Present but with nothing to say about
                                    themselves gets a plain green dot;
                                    AvailabilityDot draws nothing for plain
                                    "available" precisely so it can be used
                                    everywhere. Here being online IS the
                                    information, so it needs its own mark.
                                */}
                            {present.has(member.id) &&
                            member.availability === 'available' ? (
                                <span
                                    title={t('chat_ui.members.online')}
                                    className="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-sidebar bg-emerald-500"
                                />
                            ) : (
                                <AvailabilityDot
                                    availability={member.availability}
                                    className="absolute -right-0.5 -bottom-0.5"
                                />
                            )}
                        </span>

                        <span
                            className={cn(
                                'min-w-0 flex-1',
                                // Away rather than gone: still listed, so
                                // the count stays honest, but visibly not
                                // somebody to expect an answer from.
                                !present.has(member.id) && 'opacity-55',
                            )}
                        >
                            <span className="flex min-w-0 items-center gap-1.5">
                                {/*
                                    The name is the way to somebody's page here
                                    too, so that clicking a name means the same
                                    thing wherever it appears.
                                */}
                                <Link
                                    href={memberProfile({
                                        workspace: workspaceSlug,
                                        member: member.id,
                                    })}
                                    className="truncate text-sm hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {member.name}
                                </Link>
                                {/*
                                        Nothing marks you out in the list except
                                        this. Leaving yourself out instead would
                                        make the count disagree with what the
                                        person beside you sees.
                                    */}
                                {member.id === currentUserId && (
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {t('chat_ui.members.you')}
                                    </span>
                                )}
                                <MemberStatus
                                    emoji={member.statusEmoji}
                                    text={member.statusText}
                                />
                            </span>
                            {member.statusText && (
                                <span className="block truncate text-xs text-muted-foreground">
                                    {member.statusText}
                                </span>
                            )}
                        </span>
                    </div>
                ))}
            </div>
        </aside>
    );
}
