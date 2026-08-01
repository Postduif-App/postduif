import { cn } from '@/lib/utils';

/**
 * Marks somebody as external, wherever their name appears.
 *
 * Outlined rather than filled, the same as in the workspace member list: the
 * filled badges in this app mean elevated (owner, admin), and a guest is the
 * opposite of that. One component so the two screens cannot drift apart.
 */
export function GuestBadge({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'shrink-0 rounded-sm border border-amber-500/40 px-1 py-px text-[10px] font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400',
                className,
            )}
            title="Iemand van buiten, alleen in de kanalen waar ze voor zijn uitgenodigd"
        >
            Gast
        </span>
    );
}
