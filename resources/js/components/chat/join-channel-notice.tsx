import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { join } from '@/routes/chat/channels';
import type { ActiveChannel, ChatWorkspace } from '@/types/chat';

interface JoinChannelNoticeProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}

/**
 * Reading a public channel is open to the workspace; posting means joining
 * first. Without this the composer would simply be disabled with no way out.
 */
export function JoinChannelNotice({
    workspace,
    channel,
}: JoinChannelNoticeProps) {
    const [joining, setJoining] = useState(false);

    return (
        <div className="mx-4 mb-4 flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
            <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                Je leest mee in{' '}
                <span className="font-medium text-foreground">
                    #{channel.label}
                </span>
                . Word lid om te kunnen reageren.
            </p>
            <Button
                size="sm"
                disabled={joining}
                onClick={() => {
                    setJoining(true);
                    router.post(
                        join.url({
                            workspace: workspace.slug,
                            channel: channel.id,
                        }),
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setJoining(false),
                        },
                    );
                }}
            >
                {joining && <Spinner />}
                Word lid
            </Button>
        </div>
    );
}
