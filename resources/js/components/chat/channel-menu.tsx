import { Menu } from 'lucide-react';

import { setChannelMenuOpen } from '@/hooks/use-channel-menu';
import { useTranslate } from '@/hooks/use-translate';

/**
 * The way to the channel list on a screen too narrow to keep it standing.
 *
 * First thing in the header of every chat screen, and nowhere above lg — there
 * the list is simply there. It used to sit in a rail down the left edge, which
 * meant a phone gave up 3.5rem of its width to a column holding one button.
 *
 * Whether the list is open lives in a store of its own rather than in this
 * file — see use-channel-menu. This module exports a component and nothing
 * else, which is also what keeps it hot-swappable while the page is open.
 */
export function ChannelMenuButton() {
    const { t } = useTranslate();

    return (
        <button
            type="button"
            onClick={() => setChannelMenuOpen(true)}
            aria-label={t('sidebar.rail.channels')}
            className="-ml-1 flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none lg:hidden"
        >
            <Menu className="size-5" />
        </button>
    );
}
