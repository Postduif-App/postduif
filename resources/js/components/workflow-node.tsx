import {
    Archive,
    ArchiveRestore,
    Clock,
    Globe,
    Hash,
    Info,
    Link2,
    MessageSquare,
    MessagesSquare,
    Pin,
    PinOff,
    Send,
    Smile,
    SmilePlus,
    Sparkles,
    Split,
    Timer,
    UserPlus,
    Webhook,
    Zap,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type Glyph = ComponentType<{ className?: string }>;

/**
 * A face per trigger and per action.
 *
 * A map with a fallback rather than something the register hands down: an icon
 * is a decision about how this is drawn, and an action ought not have to know it
 * is ever drawn at all. Anything unmapped gets the fallback and still reads
 * fine — which is what keeps this from being a list somebody has to remember to
 * update when they add an action.
 */
export const TRIGGER_GLYPHS: Record<string, Glyph> = {
    'message-keyword': MessageSquare,
    'channel-join': UserPlus,
    reaction: SmilePlus,
    schedule: Clock,
    webhook: Webhook,
    link: Link2,
};

export const ACTION_GLYPHS: Record<string, Glyph> = {
    'send-channel-message': MessageSquare,
    'send-direct-message': Send,
    'reply-in-thread': MessagesSquare,
    'add-reaction': SmilePlus,
    'remove-reaction': Smile,
    'create-channel': Hash,
    'archive-channel': Archive,
    'unarchive-channel': ArchiveRestore,
    'add-channel-members': UserPlus,
    'pin-message': Pin,
    'unpin-message': PinOff,
    'get-channel-info': Info,
    'http-request': Globe,
    delay: Timer,
};

export const FALLBACK_GLYPH = Sparkles;

export const TRIGGER_GLYPH = Zap;

export const FORK_GLYPH = Split;

/** What a block is: the trigger, a fork, or one of the things in between. */
export type NodeKind = 'trigger' | 'fork' | 'action';

/**
 * One block, wherever a workflow is drawn.
 *
 * Shared between the builder and the run screen on purpose. The whole argument
 * for drawing a workflow as blocks is that somebody who has read one screen can
 * read the other — a run is the same picture with a path coloured in, and it
 * stops being that the moment the two drift apart.
 *
 * Deliberately small: an icon, a name, one line about it. Anything longer lives
 * beside the drawing rather than inside it.
 */
export function WorkflowNode({
    glyph: Glyph,
    kind = 'action',
    label,
    summary,
    number,
    tone,
    selected = false,
    muted = false,
    onSelect,
    tools,
    trailing,
}: {
    glyph: Glyph;
    kind?: NodeKind;
    label: string;
    summary: string | null;
    /**
     * The number the runner files this block's result under, so that somebody
     * writing {{ steps.3.channel.id }} can see which block is 3 without counting
     * lanes in their head.
     */
    number?: number;
    /** A class for the icon, when what happened here has a colour. */
    tone?: string;
    selected?: boolean;
    /** For a block that did nothing: it is there, it just has no weight. */
    muted?: boolean;
    onSelect?: () => void;
    tools?: ReactNode;
    trailing?: ReactNode;
}) {
    const inside = (
        <>
            <span
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-md',
                    tone ??
                        (kind === 'trigger'
                            ? 'bg-primary/10 text-primary'
                            : kind === 'fork'
                              ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                              : 'bg-muted text-muted-foreground'),
                )}
            >
                <Glyph className="size-4" />
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {number !== undefined && (
                        <span className="mr-1.5 text-xs font-normal text-muted-foreground/70 tabular-nums">
                            {number}
                        </span>
                    )}
                    {label}
                </span>

                {summary !== null && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {summary}
                    </span>
                )}
            </span>

            {trailing}
        </>
    );

    const shape = cn(
        'flex w-full items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-left transition-colors',
        // The trigger is not one of the steps and should not read as the first
        // of them: dashed, so the eye separates "wanneer" from "wat er dan
        // gebeurt" before reading a word.
        kind === 'trigger' && 'border-dashed',
        muted && 'opacity-60',
        onSelect && 'hover:border-foreground/30',
        selected && 'border-primary ring-1 ring-primary',
    );

    return (
        <div className="group/node relative">
            {onSelect ? (
                <button
                    type="button"
                    onClick={onSelect}
                    aria-current={selected}
                    className={shape}
                >
                    {inside}
                </button>
            ) : (
                <div className={shape}>{inside}</div>
            )}

            {tools && (
                <div className="absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-1 opacity-0 transition-opacity group-focus-within/node:opacity-100 group-hover/node:opacity-100">
                    {tools}
                </div>
            )}
        </div>
    );
}
