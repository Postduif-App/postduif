import type { PropsWithChildren } from 'react';

import { ConnectionBanner } from '@/components/connection-banner';

/**
 * The chat's shell, which is deliberately almost nothing.
 *
 * Every chat screen owns the full viewport and draws its own chrome — sidebar,
 * conversation, panels — so there is no frame to put around them. What there is
 * room for is the one thing that belongs to all of them at once: whether the
 * socket they all depend on is still there.
 *
 * A fragment rather than a wrapping element on purpose. The screens below are
 * `h-screen` flex containers, and an extra div between them and the body would
 * be one more box to keep in step with every one of them.
 */
export default function ChatLayout({ children }: PropsWithChildren) {
    return (
        <>
            <ConnectionBanner />
            {children}
        </>
    );
}
