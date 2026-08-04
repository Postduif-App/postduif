import { Form } from '@inertiajs/react';
import { Hash, Lock } from 'lucide-react';
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
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { update as updateChannels } from '@/routes/workspace/members/channels';

export interface ChannelOption {
    id: number;
    name: string;
    type: string;
}

interface Guest {
    id: number;
    name: string;
    channelIds: number[];
}

interface GuestChannelsDialogProps {
    /** The guest being edited, or null when the dialog is closed. */
    guest: Guest | null;
    channels: ChannelOption[];
    onOpenChange: (open: boolean) => void;
}

/**
 * Which channels a guest belongs to, as one list you tick and submit.
 *
 * The whole list goes over the wire rather than one add or one remove, so what
 * is on screen when you press save is exactly what the guest ends up with. See
 * SyncGuestChannels for the server side of that.
 */
export function GuestChannelsDialog({
    guest,
    channels,
    onOpenChange,
}: GuestChannelsDialogProps) {
    const { t } = useTranslate();

    return (
        <Dialog open={guest !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('components.guest_channels.title', {
                            name: guest?.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('components.guest_channels.description')}
                    </DialogDescription>
                </DialogHeader>

                {guest && (
                    <GuestChannelsForm
                        // One dialog serves every row, so the ticked boxes have
                        // to start over per guest. A key does that on mount
                        // rather than by resetting state after the fact.
                        key={guest.id}
                        guest={guest}
                        channels={channels}
                        onOpenChange={onOpenChange}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function GuestChannelsForm({
    guest,
    channels,
    onOpenChange,
}: GuestChannelsDialogProps & { guest: Guest }) {
    const { t } = useTranslate();
    const [picked, setPicked] = useState<number[]>(guest.channelIds);

    const toggle = (id: number) =>
        setPicked((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return (
        <Form
            {...updateChannels.form(guest.id)}
            options={{ preserveScroll: true }}
            onSuccess={() => onOpenChange(false)}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    {/* Ticking nothing sends no field at all, which the server
                        reads as "in no channels" — the form always carries the
                        complete list, so an absent one cannot mean anything
                        else. */}
                    {picked.map((id) => (
                        <input
                            key={id}
                            type="hidden"
                            name="channel_ids[]"
                            value={id}
                        />
                    ))}

                    {channels.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('components.guest_channels.empty')}
                        </p>
                    ) : (
                        <ScrollArea className="max-h-60 rounded-lg border">
                            <div className="p-1">
                                {channels.map((channel) => (
                                    <label
                                        key={channel.id}
                                        className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted/50"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={picked.includes(
                                                channel.id,
                                            )}
                                            onChange={() => toggle(channel.id)}
                                        />
                                        {channel.type === 'private' ? (
                                            <Lock className="size-3.5 shrink-0 text-muted-foreground" />
                                        ) : (
                                            <Hash className="size-3.5 shrink-0 text-muted-foreground" />
                                        )}
                                        <span className="truncate">
                                            {channel.name}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </ScrollArea>
                    )}

                    <InputError message={errors.channel_ids} />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('settings.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {t('settings.actions.save')}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
