import { Form } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { store } from '@/routes/chat/polls';

/** One answer per line, blanks dropped. */
function splitOptions(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);
}

/**
 * How long the channel gets. Hours, because that is what somebody decides.
 *
 * The wording lives beside the number rather than in the list, so the list can
 * stay a plain constant while the labels come out of the language files.
 */
const DURATIONS = [
    { value: '', key: 'dialogs.poll.duration_until_closed' },
    { value: '1', key: 'dialogs.poll.duration_one_hour' },
    { value: '8', key: 'dialogs.poll.duration_eight_hours' },
    { value: '24', key: 'dialogs.poll.duration_one_day' },
    { value: '72', key: 'dialogs.poll.duration_three_days' },
    { value: '168', key: 'dialogs.poll.duration_one_week' },
] as const;

/**
 * Putting a question to the channel.
 *
 * Always driven from outside — there is no trigger of its own. The button
 * beside the message field and the "/poll" command both open this one, the same
 * arrangement the transfer and secret dialogs ended up with after they were
 * found to exist twice.
 */
export function PollDialog({
    workspaceSlug,
    channelId,
    open,
    onOpenChange,
}: {
    workspaceSlug: string;
    channelId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslate();
    const [options, setOptions] = useState('');

    const parsed = splitOptions(options);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('dialogs.poll.title')}</DialogTitle>
                    <DialogDescription>
                        {t('dialogs.poll.description')}
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
                        setOptions('');
                        onOpenChange(false);
                    }}
                    disableWhileProcessing
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="poll_question">
                                    {t('dialogs.poll.question_label')}
                                </Label>
                                <Input
                                    id="poll_question"
                                    name="question"
                                    required
                                    autoFocus
                                    maxLength={200}
                                    placeholder={t(
                                        'dialogs.poll.question_placeholder',
                                    )}
                                />
                                <InputError message={errors.question} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="poll_options">
                                    {t('dialogs.poll.options_label')}
                                </Label>
                                <textarea
                                    id="poll_options"
                                    rows={4}
                                    value={options}
                                    onChange={(event) =>
                                        setOptions(event.target.value)
                                    }
                                    placeholder={t(
                                        'dialogs.poll.options_placeholder',
                                    )}
                                    className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />
                                {parsed.map((label, index) => (
                                    <input
                                        key={`${label}-${index}`}
                                        type="hidden"
                                        name="options[]"
                                        value={label}
                                    />
                                ))}
                                <p className="text-xs text-muted-foreground">
                                    {t('dialogs.poll.options_hint')}
                                </p>
                                <InputError
                                    message={
                                        errors.options ?? errors['options.0']
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="poll_closes">
                                    {t('dialogs.poll.duration_label')}
                                </Label>
                                <select
                                    id="poll_closes"
                                    name="closes_in_hours"
                                    defaultValue=""
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {DURATIONS.map((option) => (
                                        <option
                                            key={option.key}
                                            value={option.value}
                                        >
                                            {t(option.key)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.closes_in_hours} />
                            </div>

                            <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm">
                                {/* An unticked checkbox sends nothing at all. */}
                                <input
                                    type="hidden"
                                    name="allows_multiple"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="allows_multiple"
                                    value="1"
                                />
                                {t('dialogs.poll.allows_multiple')}
                            </label>

                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={processing || parsed.length < 2}
                                >
                                    {processing && <Spinner />}
                                    <BarChart3 className="size-4" />
                                    {t('dialogs.poll.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
