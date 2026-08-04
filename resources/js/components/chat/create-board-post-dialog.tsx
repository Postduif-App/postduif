import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { store } from '@/routes/chat/board';
import type { ChatWorkspace } from '@/types/chat';

/**
 * Putting something on the prikbord.
 *
 * A dialog rather than a composer at the foot of the list, unlike a channel.
 * A message is typed into the conversation it belongs to; a notice is written
 * once, has to carry a title, and is read by people who are not here yet — that
 * is a form, and pretending otherwise produces one-line notices called
 * "hoi".
 */
export function CreateBoardPostDialog({
    workspace,
    open,
    onOpenChange,
}: {
    workspace: ChatWorkspace;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = () => {
        if (title.trim() === '' || body.trim() === '' || saving) {
            return;
        }

        setSaving(true);

        router.post(
            store.url(workspace.slug),
            { title, body },
            {
                // The redirect lands on the notice that was just made, so there
                // is nothing to preserve and nothing to clear by hand.
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('dialogs.board_post.title')}</DialogTitle>
                    <DialogDescription>
                        {t('dialogs.board_post.description', {
                            workspace: workspace.name,
                        })}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="board-title">
                            {t('dialogs.board_post.title_label')}
                        </Label>
                        <Input
                            id="board-title"
                            value={title}
                            maxLength={120}
                            placeholder={t(
                                'dialogs.board_post.title_placeholder',
                            )}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="board-body">
                            {t('dialogs.board_post.body_label')}
                        </Label>
                        <textarea
                            id="board-body"
                            value={body}
                            rows={8}
                            maxLength={8000}
                            className="w-full resize-y rounded-md border bg-background p-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            onChange={(event) => setBody(event.target.value)}
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        {t('dialogs.actions.cancel')}
                    </Button>
                    <Button
                        disabled={
                            saving || title.trim() === '' || body.trim() === ''
                        }
                        onClick={submit}
                    >
                        {saving && <Spinner className="size-4" />}
                        {t('dialogs.board_post.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
