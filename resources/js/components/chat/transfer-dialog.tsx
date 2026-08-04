import { Form } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { readableSize } from '@/lib/file-size';
import { store } from '@/routes/chat/transfers';

/**
 * Sending files that are too big to hang on a message, from the message field.
 *
 * The bridge between the chat and the transfers screen. What lands in the
 * channel is an ordinary message holding the link — the card under it is drawn
 * by the same code that draws one for a link anybody pastes, so nothing here
 * has to be a special kind of message.
 *
 * The audience is fixed at members of the workspace rather than offered as a
 * choice. A link you put in a channel is for the people in that channel, and a
 * composer is the wrong place to be weighing up who else might end up holding
 * it — the settings screen is there for the transfer that needs thinking about.
 */
export function TransferDialog({
    workspaceSlug,
    channelId,
    maxKb,
    maxDays,
    disabled = false,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    channelId: number;
    maxKb: number;
    maxDays: number;
    disabled?: boolean;
    /**
     * Driven from outside when something other than the button opens it — the
     * slash command does. Left undefined, the dialog keeps its own state and
     * draws its own trigger, which is what the button beside the message field
     * wants.
     */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();
    const [ownOpen, setOwnOpen] = useState(false);

    const controlled = open !== undefined;
    const isOpen = controlled ? open : ownOpen;
    const setOpen = controlled ? (onOpenChange ?? (() => {})) : setOwnOpen;

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {/*
                No trigger when somebody else is opening this: two ways in would
                mean two buttons, and the command is meant to replace the click
                rather than sit beside it.
            */}
            {!controlled && (
                <DialogTrigger asChild>
                    <Button
                        size="icon"
                        variant="ghost"
                        disabled={disabled}
                        title={t('dialogs.transfer.trigger')}
                        aria-label={t('dialogs.transfer.trigger')}
                    >
                        <Send className="size-4" />
                    </Button>
                </DialogTrigger>
            )}

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('dialogs.transfer.title')}</DialogTitle>
                    <DialogDescription>
                        {t('dialogs.transfer.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action={store.url({ workspace: workspaceSlug })}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    disableWhileProcessing
                    className="space-y-4"
                >
                    {({ processing, progress, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="channel_id"
                                value={channelId}
                            />
                            {/*
                                Fixed rather than chosen — see the note on the
                                component. The server validates it all the same.
                            */}
                            <input
                                type="hidden"
                                name="audience"
                                value="workspace-members"
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="transfer_files">
                                    {t('dialogs.transfer.files_label')}
                                </Label>
                                <Input
                                    id="transfer_files"
                                    name="files[]"
                                    type="file"
                                    multiple
                                    required
                                    autoFocus
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t('dialogs.transfer.files_hint', {
                                        size: readableSize(
                                            maxKb * 1024,
                                            formats.number,
                                        ),
                                    })}
                                </p>
                                <InputError message={errors.files} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer_title">
                                    {t('dialogs.transfer.title_label')}
                                </Label>
                                <Input
                                    id="transfer_title"
                                    name="title"
                                    maxLength={120}
                                    placeholder={t(
                                        'dialogs.transfer.title_placeholder',
                                    )}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer_days">
                                    {t('dialogs.transfer.days_label')}
                                </Label>
                                <Input
                                    id="transfer_days"
                                    name="valid_for_days"
                                    type="number"
                                    min={1}
                                    max={maxDays}
                                    defaultValue={Math.min(7, maxDays)}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {tChoice(
                                        'dialogs.transfer.days_hint',
                                        maxDays,
                                    )}
                                </p>
                                <InputError message={errors.valid_for_days} />
                            </div>

                            {/*
                                A bar rather than a spinner: this dialog exists
                                for files measured in gigabytes, so "is it doing
                                anything" is a question somebody will have for
                                minutes.
                            */}
                            {progress && (
                                <div className="space-y-1">
                                    <div
                                        role="progressbar"
                                        aria-valuenow={Math.round(
                                            progress.percentage ?? 0,
                                        )}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            className="h-full bg-primary transition-[width]"
                                            style={{
                                                width: `${progress.percentage ?? 0}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('dialogs.transfer.uploading', {
                                            percentage: Math.round(
                                                progress.percentage ?? 0,
                                            ),
                                        })}
                                    </p>
                                </div>
                            )}

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && !progress && <Spinner />}
                                    {t('dialogs.transfer.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
