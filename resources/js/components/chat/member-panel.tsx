import { Users } from 'lucide-react';
import { useState } from 'react';

import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { useInitials } from '@/hooks/use-initials';
import { useWorkspacePresence } from '@/hooks/use-workspace-presence';
import { cn } from '@/lib/utils';
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
}: {
    members: ChannelMember[];
    currentUserId: number;
    workspaceSlug: string;
}) {
    const getInitials = useInitials();
    const [open, setOpen] = useState(true);
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
        <aside
            className={cn(
                'hidden shrink-0 flex-col border-l bg-sidebar transition-[width] lg:flex',
                open ? 'w-60' : 'w-12',
            )}
        >
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                title={open ? 'Ledenlijst inklappen' : 'Ledenlijst uitklappen'}
                aria-expanded={open}
                className="flex items-center gap-2 border-b px-3 py-3 text-sm font-medium text-sidebar-foreground/80 transition-colors hover:text-sidebar-foreground"
            >
                <Users className="size-4 shrink-0" />
                {open && (
                    <>
                        <span>Leden</span>
                        <span className="ml-auto text-xs text-muted-foreground">
                            {members.length}
                        </span>
                    </>
                )}
            </button>

            {open && (
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
                                        title="Nu online"
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
                                <span className="flex items-center gap-1.5">
                                    <span className="truncate text-sm">
                                        {member.name}
                                    </span>
                                    {/*
                                        Nothing marks you out in the list except
                                        this. Leaving yourself out instead would
                                        make the count disagree with what the
                                        person beside you sees.
                                    */}
                                    {member.id === currentUserId && (
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            jij
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
            )}
        </aside>
    );
}
