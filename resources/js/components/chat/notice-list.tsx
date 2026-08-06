import { router } from '@inertiajs/react';
import { Sparkles, X } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { destroy } from '@/routes/chat/notices';
import type { ChatWorkspace, EphemeralNotice } from '@/types/chat';

interface NoticeListProps {
    workspace: ChatWorkspace;
    channelId: number;
    notices: EphemeralNotice[];
}

/**
 * The things this member alone was told in this channel.
 *
 * Under the conversation rather than among it, and that is deliberate. A notice
 * is the answer to something you just did — a command you typed, a button you
 * pressed — so it belongs where you were looking, which is the bottom. Mixing
 * them into the list would also mean giving them a place in the message
 * grouping, where they have none: they have no author to group under and no
 * position in what the channel said.
 *
 * Dashed rather than bordered, and nothing to click but the cross. There is no
 * replying to one, no reacting, no saving and no pinning — a toolbar of buttons
 * that all do nothing would say the opposite.
 */
export function NoticeList({ workspace, channelId, notices }: NoticeListProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    if (notices.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1.5 px-5 pb-2">
            {notices.map((notice) => (
                <div
                    key={notice.id}
                    className="flex items-start gap-2 rounded-lg border border-dashed bg-muted/30 px-3 py-2 text-sm"
                >
                    <Sparkles className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                            {notice.authorName && (
                                <span className="font-medium text-foreground">
                                    {notice.authorName}
                                </span>
                            )}
                            <span>{t('chat_ui.notice.only_you')}</span>
                            {notice.createdAt && (
                                <span>
                                    {formats.time.format(
                                        new Date(notice.createdAt),
                                    )}
                                </span>
                            )}
                        </p>
                        <p className="break-words">{notice.body}</p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            router.delete(
                                destroy.url({
                                    workspace: workspace.slug,
                                    channel: channelId,
                                    notice: notice.id,
                                }),
                                { preserveScroll: true },
                            )
                        }
                        aria-label={t('chat_ui.notice.dismiss')}
                        className="shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            ))}
        </div>
    );
}
