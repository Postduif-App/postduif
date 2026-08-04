import { Form } from '@inertiajs/react';
import { Globe, Lock, MessageSquare, Newspaper } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import { store } from '@/routes/chat/channels';
import type { ChannelType, ChatWorkspace } from '@/types/chat';

interface CreateChannelDialogProps {
    workspace: ChatWorkspace;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** The one line lookup, so the option lists below can be built with it. */
type Translate = ReturnType<typeof useTranslate>['t'];

interface Choice<T> {
    value: T;
    label: string;
    hint: string;
    icon: typeof Globe;
}

function visibilityChoices(
    t: Translate,
): Choice<Extract<ChannelType, 'public' | 'private'>>[] {
    return [
        {
            value: 'public',
            label: t('channels.visibility.public'),
            hint: t('channels.visibility.public_hint'),
            icon: Globe,
        },
        {
            value: 'private',
            label: t('channels.visibility.private'),
            hint: t('channels.visibility.private_hint'),
            icon: Lock,
        },
    ];
}

/**
 * How the channel reads. A separate question from the visibility above it: an
 * internal newsletter is exactly the kind of feed that belongs behind a private
 * channel, so the two choices do not constrain each other.
 */
function layoutChoices(t: Translate): Choice<'chat' | 'feed'>[] {
    return [
        {
            value: 'chat',
            label: t('channels.layout.chat'),
            hint: t('channels.layout.chat_hint'),
            icon: MessageSquare,
        },
        {
            value: 'feed',
            label: t('channels.layout.feed'),
            hint: t('channels.layout.feed_hint'),
            icon: Newspaper,
        },
    ];
}

export function CreateChannelDialog({
    workspace,
    open,
    onOpenChange,
}: CreateChannelDialogProps) {
    const { t } = useTranslate();
    const [name, setName] = useState('');
    const [type, setType] = useState<'public' | 'private'>('public');
    const [layout, setLayout] = useState<'chat' | 'feed'>('chat');

    const visibility = visibilityChoices(t);
    const layouts = layoutChoices(t);

    // Preview the slug the server will store, so nobody is surprised that
    // "Nieuwe Klanten" becomes #nieuwe-klanten after saving.
    const slug = name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setName('');
                    setType('public');
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('channels.create.title')}</DialogTitle>
                    <DialogDescription>
                        {t('channels.create.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form(workspace.slug)}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="type" value={type} />
                            <input type="hidden" name="layout" value={layout} />

                            <div className="grid gap-2">
                                <Label htmlFor="channel-name">
                                    {t('channels.fields.name')}
                                </Label>
                                <Input
                                    id="channel-name"
                                    name="name"
                                    value={name}
                                    autoFocus
                                    maxLength={80}
                                    placeholder={t(
                                        'channels.create.name_placeholder',
                                    )}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {slug === ''
                                        ? t('channels.create.slug_hint')
                                        : t('channels.create.slug_preview', {
                                              slug,
                                          })}
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('channels.visibility.heading')}
                                </legend>
                                {visibility.map((option) => (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                            type === option.value
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="visibility"
                                            value={option.value}
                                            checked={type === option.value}
                                            onChange={() =>
                                                setType(option.value)
                                            }
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="flex items-center gap-1.5 text-sm font-medium">
                                                <option.icon className="size-3.5" />
                                                {option.label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {option.hint}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <InputError message={errors.type} />
                            </fieldset>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('channels.layout.heading')}
                                </legend>
                                {layouts.map((option) => (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                            layout === option.value
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="channel-layout"
                                            value={option.value}
                                            checked={layout === option.value}
                                            onChange={() =>
                                                setLayout(option.value)
                                            }
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="flex items-center gap-1.5 text-sm font-medium">
                                                <option.icon className="size-3.5" />
                                                {option.label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {option.hint}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                                <InputError message={errors.layout} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="channel-topic">
                                    {t('channels.fields.topic')}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        {t('channels.fields.topic_optional')}
                                    </span>
                                </Label>
                                <Input
                                    id="channel-topic"
                                    name="topic"
                                    maxLength={255}
                                    placeholder={t(
                                        'channels.fields.topic_placeholder',
                                    )}
                                />
                                <InputError message={errors.topic} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('channels.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || slug === ''}
                                >
                                    {processing && <Spinner />}
                                    {t('channels.actions.create')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
