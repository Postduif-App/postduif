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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { store } from '@/routes/chat/invitations';
import type { ChannelSummary, ChatWorkspace } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

interface InvitePeopleDialogProps {
    workspace: ChatWorkspace;
    /** Non-DM channels this member can see, and so can hand out. */
    channels: ChannelSummary[];
    /** Ticked from the start — usually the channel you were just looking at. */
    initialChannelId?: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** What each role is called and what it means, looked up when the dialog draws. */
const ROLES: {
    value: 'guest' | 'member';
    label: TranslationKey;
    hint: TranslationKey;
}[] = [
    {
        value: 'guest',
        label: 'actions.invite.guest',
        hint: 'actions.invite.guest_hint',
    },
    {
        value: 'member',
        label: 'actions.invite.member',
        hint: 'actions.invite.member_hint',
    },
];

export function InvitePeopleDialog({
    workspace,
    channels,
    initialChannelId,
    open,
    onOpenChange,
}: InvitePeopleDialogProps) {
    const { t } = useTranslate();
    const [role, setRole] = useState<'guest' | 'member'>('guest');
    const [picked, setPicked] = useState<number[]>(() =>
        initialChannelId === undefined ? [] : [initialChannelId],
    );

    const toggle = (id: number) =>
        setPicked((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    const reset = () => {
        setRole('guest');
        setPicked(initialChannelId === undefined ? [] : [initialChannelId]);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('actions.invite.title', {
                            workspace: workspace.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('actions.invite.intro')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form(workspace.slug)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        reset();
                        onOpenChange(false);
                    }}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="role" value={role} />
                            {/* One field per ticked channel: an array of ids is
                                what the server validates, and this keeps the
                                form a plain form. */}
                            {picked.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="channel_ids[]"
                                    value={id}
                                />
                            ))}

                            <div className="grid gap-2">
                                <Label htmlFor="invite-email">
                                    {t('actions.invite.email_field')}
                                </Label>
                                <Input
                                    id="invite-email"
                                    name="email"
                                    type="email"
                                    autoFocus
                                    required
                                    placeholder={t(
                                        'actions.invite.email_placeholder',
                                    )}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('actions.invite.role_question')}
                                </legend>
                                {ROLES.map((option) => (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                            role === option.value
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="role-choice"
                                            value={option.value}
                                            checked={role === option.value}
                                            onChange={() =>
                                                setRole(option.value)
                                            }
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                {t(option.label)}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t(option.hint)}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <InputError message={errors.role} />
                            </fieldset>

                            {role === 'guest' && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        {t('actions.invite.guest_channels')}
                                    </legend>

                                    {channels.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            {t('actions.invite.no_channels')}
                                        </p>
                                    ) : (
                                        <ScrollArea className="max-h-44 rounded-lg border">
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
                                                            onChange={() =>
                                                                toggle(
                                                                    channel.id,
                                                                )
                                                            }
                                                        />
                                                        {channel.type ===
                                                        'private' ? (
                                                            <Lock className="size-3.5 shrink-0 text-muted-foreground" />
                                                        ) : (
                                                            <Hash className="size-3.5 shrink-0 text-muted-foreground" />
                                                        )}
                                                        <span className="truncate">
                                                            {channel.label}
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </ScrollArea>
                                    )}

                                    <InputError message={errors.channel_ids} />
                                </fieldset>
                            )}

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('actions.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('actions.invite.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
