import { Form } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
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
import { useTranslate } from '@/hooks/use-translate';
import { store } from '@/routes/chat/secrets';

/** One key per line, blanks dropped — people paste these out of a .env file. */
function splitKeys(value: string): string[] {
    return (
        value
            .split('\n')
            .map((line) => line.trim())
            // A pasted .env line is "KEY=value"; take the name and quietly leave
            // the value behind, which is the one thing that must not travel here.
            .map((line) => line.split('=')[0].trim())
            .filter((line) => line.length > 0)
    );
}

/**
 * Asking somebody for values that must not be pasted into the conversation.
 *
 * The counterpart of TransferDialog, and built the same way — including being
 * openable from outside, so the slash command can reach it.
 *
 * One detail is load-bearing: what is typed here is the *names* of the keys,
 * never the values. Somebody pasting a block out of their .env would otherwise
 * put the very secrets they are asking about into a chat form, which is the
 * problem this feature exists to solve. splitKeys() drops everything after the
 * "=" for that reason.
 */
export function SecretRequestDialog({
    workspaceSlug,
    channelId,
    disabled = false,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    channelId: number;
    disabled?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const [ownOpen, setOwnOpen] = useState(false);
    const [keys, setKeys] = useState('');

    const controlled = open !== undefined;
    const isOpen = controlled ? open : ownOpen;
    const setOpen = controlled ? (onOpenChange ?? (() => {})) : setOwnOpen;

    const parsed = splitKeys(keys);

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {!controlled && (
                <DialogTrigger asChild>
                    <Button
                        size="icon"
                        variant="ghost"
                        disabled={disabled}
                        title={t('dialogs.secret_request.trigger')}
                        aria-label={t('dialogs.secret_request.trigger')}
                    >
                        <KeyRound className="size-4" />
                    </Button>
                </DialogTrigger>
            )}

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('dialogs.secret_request.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('dialogs.secret_request.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    action={store.url({
                        workspace: workspaceSlug,
                        channel: channelId,
                    })}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setKeys('');
                        setOpen(false);
                    }}
                    disableWhileProcessing
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="secret_title">
                                    {t('dialogs.secret_request.purpose_label')}
                                </Label>
                                <Input
                                    id="secret_title"
                                    name="title"
                                    required
                                    autoFocus
                                    maxLength={120}
                                    placeholder={t(
                                        'dialogs.secret_request.purpose_placeholder',
                                    )}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="secret_keys">
                                    {t('dialogs.secret_request.keys_label')}
                                </Label>
                                <textarea
                                    id="secret_keys"
                                    rows={4}
                                    value={keys}
                                    onChange={(event) =>
                                        setKeys(event.target.value)
                                    }
                                    placeholder={'DB_PASSWORD\nMAIL_USERNAME'}
                                    className="w-full resize-none rounded-md border bg-background px-3 py-2 font-mono text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                                {parsed.map((name, index) => (
                                    <input
                                        key={`${name}-${index}`}
                                        type="hidden"
                                        name="keys[]"
                                        value={name}
                                    />
                                ))}
                                <p className="text-xs text-muted-foreground">
                                    {t('dialogs.secret_request.keys_hint')}
                                </p>
                                <InputError
                                    message={errors.keys ?? errors['keys.0']}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="secret_days">
                                    {t('dialogs.secret_request.days_label')}
                                </Label>
                                <Input
                                    id="secret_days"
                                    name="valid_for_days"
                                    type="number"
                                    min={1}
                                    max={30}
                                    defaultValue={7}
                                />
                                <InputError message={errors.valid_for_days} />
                            </div>

                            <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm">
                                {/* An unticked checkbox sends nothing at all. */}
                                <input
                                    type="hidden"
                                    name="burn_after_reading"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="burn_after_reading"
                                    value="1"
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="block font-medium">
                                        {t('dialogs.secret_request.burn_label')}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {t('dialogs.secret_request.burn_hint')}
                                    </span>
                                </span>
                            </label>

                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={processing || parsed.length === 0}
                                >
                                    {processing && <Spinner />}
                                    {t('dialogs.secret_request.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
