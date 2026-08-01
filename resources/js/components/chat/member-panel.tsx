import { Users } from 'lucide-react';
import { useState } from 'react';

import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { useInitials } from '@/hooks/use-initials';
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
}: {
    members: ChannelMember[];
    currentUserId: number;
}) {
    const getInitials = useInitials();
    const [open, setOpen] = useState(true);

    if (members.length === 0) {
        return null;
    }

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
                    {members.map((member) => (
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
                                <AvailabilityDot
                                    availability={member.availability}
                                    className="absolute -right-0.5 -bottom-0.5"
                                />
                            </span>

                            <span className="min-w-0 flex-1">
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
