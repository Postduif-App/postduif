import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * The line under a field that says what went wrong.
 *
 * `role="alert"` rather than an `aria-live` wrapper: this paragraph does not
 * exist until there is something to say, and a live region has to be in the
 * document *before* it changes for the change to be announced. An alert is the
 * one role meant for text that arrives with the element it lives in.
 */
export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p
            role="alert"
            {...props}
            className={cn('text-sm text-red-600 dark:text-red-400', className)}
        >
            {message}
        </p>
    ) : null;
}
