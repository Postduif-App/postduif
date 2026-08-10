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
import { store } from '@/routes/chat/documents';
import type { ChatWorkspace } from '@/types/chat';

interface CreateDocumentDialogProps {
    workspace: ChatWorkspace;
    channelId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * Starting a document asks for one thing: what it is called.
 *
 * No description, no template, no content. The document is written in the
 * editor, and a dialog that asked for a first paragraph would be a second,
 * much worse editor that everybody has to pass through on the way to the real
 * one.
 */
export function CreateDocumentDialog({
    workspace,
    channelId,
    open,
    onOpenChange,
}: CreateDocumentDialogProps) {
    const { t } = useTranslate();
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = () => {
        const trimmed = title.trim();

        if (trimmed === '' || saving) {
            return;
        }

        setSaving(true);
        setError(null);

        router.post(
            store.url({ workspace: workspace.slug, channel: channelId }),
            { title: trimmed },
            {
                onSuccess: () => {
                    // Cleared on the way out, so the next document does not open
                    // with the last one's name already typed in.
                    setTitle('');
                    onOpenChange(false);
                },
                onError: (errors) => setError(errors.title ?? null),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('documents.create.title')}</DialogTitle>
                    <DialogDescription>
                        {t('documents.create.description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
                    <Label htmlFor="document-title">
                        {t('documents.create.name')}
                    </Label>

                    <Input
                        id="document-title"
                        value={title}
                        autoFocus
                        onChange={(event) => setTitle(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                submit();
                            }
                        }}
                        placeholder={t('documents.create.placeholder')}
                    />

                    {error !== null && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={saving}
                    >
                        {t('documents.create.cancel')}
                    </Button>

                    <Button
                        onClick={submit}
                        disabled={saving || title.trim() === ''}
                    >
                        {saving && <Spinner />}
                        {t('documents.create.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
