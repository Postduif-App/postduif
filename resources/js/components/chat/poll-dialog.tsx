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
import { store } from '@/routes/chat/polls';

/** One answer per line, blanks dropped. */
function splitOptions(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 0);
}

/** How long the channel gets. Hours, because that is what somebody decides. */
const DURATIONS: { value: string; label: string }[] = [
    { value: '', label: 'Tot ik hem sluit' },
    { value: '1', label: '1 uur' },
    { value: '8', label: '8 uur' },
    { value: '24', label: '1 dag' },
    { value: '72', label: '3 dagen' },
    { value: '168', label: '1 week' },
];

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
    const [options, setOptions] = useState('');

    const parsed = splitOptions(options);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Een vraag stellen</DialogTitle>
                    <DialogDescription>
                        Iedereen in dit kanaal kan stemmen — en ziet wie wat
                        stemt.
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
                                <Label htmlFor="poll_question">Vraag</Label>
                                <Input
                                    id="poll_question"
                                    name="question"
                                    required
                                    autoFocus
                                    maxLength={200}
                                    placeholder="Wanneer doen we de retro?"
                                />
                                <InputError message={errors.question} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="poll_options">Antwoorden</Label>
                                <textarea
                                    id="poll_options"
                                    rows={4}
                                    value={options}
                                    onChange={(event) =>
                                        setOptions(event.target.value)
                                    }
                                    placeholder={'Dinsdag\nWoensdag'}
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
                                    Eén per regel, minstens twee.
                                </p>
                                <InputError
                                    message={
                                        errors.options ?? errors['options.0']
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="poll_closes">
                                    Open gedurende
                                </Label>
                                <select
                                    id="poll_closes"
                                    name="closes_in_hours"
                                    defaultValue=""
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {DURATIONS.map((option) => (
                                        <option
                                            key={option.label}
                                            value={option.value}
                                        >
                                            {option.label}
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
                                Meerdere antwoorden mogen
                            </label>

                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={processing || parsed.length < 2}
                                >
                                    {processing && <Spinner />}
                                    <BarChart3 className="size-4" />
                                    Vraag plaatsen
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
