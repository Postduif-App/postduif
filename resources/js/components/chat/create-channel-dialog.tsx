import { Form } from '@inertiajs/react';
import { Globe, Lock } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import { store } from '@/routes/chat/channels';
import type { ChannelType, ChatWorkspace } from '@/types/chat';

interface CreateChannelDialogProps {
    workspace: ChatWorkspace;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const VISIBILITY: {
    value: Extract<ChannelType, 'public' | 'private'>;
    label: string;
    hint: string;
    icon: typeof Globe;
}[] = [
    {
        value: 'public',
        label: 'Openbaar',
        hint: 'Iedereen in de workspace kan meelezen en zich aansluiten.',
        icon: Globe,
    },
    {
        value: 'private',
        label: 'Privé',
        hint: 'Alleen wie je toevoegt ziet dit kanaal bestaan.',
        icon: Lock,
    },
];

export function CreateChannelDialog({
    workspace,
    open,
    onOpenChange,
}: CreateChannelDialogProps) {
    const [name, setName] = useState('');
    const [type, setType] = useState<'public' | 'private'>('public');

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
                    <DialogTitle>Kanaal aanmaken</DialogTitle>
                    <DialogDescription>
                        Kanalen gaan meestal over één onderwerp, project of team.
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

                            <div className="grid gap-2">
                                <Label htmlFor="channel-name">Naam</Label>
                                <Input
                                    id="channel-name"
                                    name="name"
                                    value={name}
                                    autoFocus
                                    maxLength={80}
                                    placeholder="bijv. marketing"
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {slug === ''
                                        ? 'Kleine letters en streepjes.'
                                        : `Wordt #${slug}`}
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Zichtbaarheid
                                </legend>
                                {VISIBILITY.map((option) => (
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

                            <div className="grid gap-2">
                                <Label htmlFor="channel-topic">
                                    Onderwerp{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optioneel)
                                    </span>
                                </Label>
                                <Input
                                    id="channel-topic"
                                    name="topic"
                                    maxLength={255}
                                    placeholder="Waar gaat dit kanaal over?"
                                />
                                <InputError message={errors.topic} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Annuleren
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || slug === ''}
                                >
                                    {processing && <Spinner />}
                                    Aanmaken
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
