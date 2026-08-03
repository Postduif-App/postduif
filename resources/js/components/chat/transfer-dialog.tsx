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
import { store } from '@/routes/chat/transfers';

function readableSize(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit >= 2 && value < 100 ? 1 : 0).replace('.', ',')} ${units[unit]}`;
}

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
                        title="Grote bestanden versturen via een link"
                        aria-label="Grote bestanden versturen via een link"
                    >
                        <Send className="size-4" />
                    </Button>
                </DialogTrigger>
            )}

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Bestanden versturen</DialogTitle>
                    <DialogDescription>
                        Voor wat te groot is om mee te sturen met een bericht.
                        De link komt in dit kanaal te staan en werkt voor
                        iedereen in deze workspace.
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
                                    Bestanden
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
                                    Samen maximaal {readableSize(maxKb * 1024)}.
                                </p>
                                <InputError message={errors.files} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer_title">
                                    Onderwerp (optioneel)
                                </Label>
                                <Input
                                    id="transfer_title"
                                    name="title"
                                    maxLength={120}
                                    placeholder="Opnames van dinsdag"
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="transfer_days">
                                    Link blijft geldig (dagen)
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
                                    Daarna verdwijnen de bestanden. Maximaal{' '}
                                    {maxDays} dagen in deze workspace.
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
                                        Bezig met uploaden —{' '}
                                        {Math.round(progress.percentage ?? 0)}%
                                    </p>
                                </div>
                            )}

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && !progress && <Spinner />}
                                    Versturen
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
