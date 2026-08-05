import type { PropsWithChildren, ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One thing a settings screen lets you change, with its own heading.
 *
 * These screens used to be a flat stack: a heading, some fields, then the next
 * heading, all spaced by whatever the page happened to write that day. Two
 * pages disagreed on the gap between sections, and — worse — the blocks a page
 * rendered beside its wrapper instead of inside it (deleting your account, the
 * passkey list) got no gap at all, because `space-y` only reaches direct
 * children. A section that carries its own rhythm cannot be spaced wrong by the
 * page that uses it.
 *
 * @param separated Draw a hairline above the section. For the second and later
 *   sections on a page that has several: whitespace alone stops reading as a
 *   boundary once a page is long enough to scroll.
 */
export function SettingsSection({
    title,
    description,
    actions,
    separated = false,
    className,
    children,
}: PropsWithChildren<{
    title: string;
    description?: string;
    /** A button or link belonging to this section, right of its heading. */
    actions?: ReactNode;
    separated?: boolean;
    className?: string;
}>) {
    return (
        <section
            className={cn(
                'space-y-5',
                separated && 'border-t border-border/60 pt-10',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h2 className="text-base font-medium">{title}</h2>

                    {description && (
                        // Capped at a comfortable line: the column is wider than
                        // prose wants to be on the pages that manage a list.
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                {actions && <div className="shrink-0">{actions}</div>}
            </div>

            {children}
        </section>
    );
}
