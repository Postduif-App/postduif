import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, reorder, store, update } from '@/routes/chat/channels/links';
import type { ActiveChannel, ChannelLink, ChatWorkspace } from '@/types/chat';

interface ChannelLinksSectionProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}

/**
 * The buttons in the bar above the conversation, and everything you do to them.
 *
 * Saves per button rather than through the dialog's footer, like the webhook
 * panel next door: this is a list you add to and take from, not a set of
 * settings you weigh against each other and then commit. One Opslaan covering
 * both would have to decide what "cancel" means for a button you already
 * deleted.
 */
export function ChannelLinksSection({
    workspace,
    channel,
}: ChannelLinksSectionProps) {
    const [label, setLabel] = useState('');
    const [url, setUrl] = useState('');
    const [busy, setBusy] = useState(false);

    const target = { workspace: workspace.slug, channel: channel.id };
    const links = channel.links;

    const add = () => {
        if (label.trim() === '' || url.trim() === '' || busy) {
            return;
        }

        setBusy(true);
        router.post(
            store.url(target),
            { label: label.trim(), url: url.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setLabel('');
                    setUrl('');
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    /**
     * Move one button up or down by swapping it with its neighbour, then send
     * the whole list.
     *
     * The whole list because that is what the endpoint takes: moving one button
     * changes where the others sit, and a request per button would leave the
     * bar half-ordered whenever one of them failed.
     */
    const move = (index: number, delta: number) => {
        const next = [...links];
        const target_index = index + delta;

        if (target_index < 0 || target_index >= next.length) {
            return;
        }

        [next[index], next[target_index]] = [next[target_index], next[index]];

        router.put(
            reorder.url(target),
            { ids: next.map((link) => link.id) },
            { preserveScroll: true },
        );
    };

    return (
        <section className="flex flex-col gap-4">
            <div>
                <h3 className="text-sm font-medium">Knoppen</h3>
                <p className="text-xs text-muted-foreground">
                    Verschijnen in een balk boven het gesprek, voor iedereen die
                    het kanaal kan zien — gasten dus ook.
                </p>
            </div>

            {links.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                    Nog geen knoppen. Voeg er hieronder een toe.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {links.map((link, index) => (
                        <LinkRow
                            key={link.id}
                            link={link}
                            first={index === 0}
                            last={index === links.length - 1}
                            onMoveUp={() => move(index, -1)}
                            onMoveDown={() => move(index, 1)}
                            onSave={(label, url) =>
                                router.patch(
                                    update.url({ ...target, link: link.id }),
                                    { label, url },
                                    { preserveScroll: true },
                                )
                            }
                            onDelete={() =>
                                router.delete(
                                    destroy.url({ ...target, link: link.id }),
                                    { preserveScroll: true },
                                )
                            }
                        />
                    ))}
                </ul>
            )}

            <div className="flex flex-col gap-2 rounded-lg border p-3">
                <Label htmlFor="new-link-label" className="text-xs">
                    Nieuwe knop
                </Label>
                <div className="flex gap-2">
                    <Input
                        id="new-link-label"
                        value={label}
                        maxLength={40}
                        placeholder="Naam"
                        onChange={(event) => setLabel(event.target.value)}
                        className="w-40 shrink-0"
                    />
                    <Input
                        value={url}
                        type="url"
                        placeholder="https://"
                        onChange={(event) => setUrl(event.target.value)}
                        aria-label="Adres"
                    />
                    <Button
                        size="icon"
                        className="shrink-0"
                        disabled={
                            busy || label.trim() === '' || url.trim() === ''
                        }
                        onClick={add}
                        aria-label="Knop toevoegen"
                    >
                        <Plus className="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    );
}

/**
 * One button in the list: editable in place, movable, removable.
 *
 * The draft is local and only sent on blur or Enter, so every keystroke does
 * not become a request — and the arrows next to it keep working while a label
 * is half-typed, because the order is a separate endpoint.
 */
function LinkRow({
    link,
    first,
    last,
    onMoveUp,
    onMoveDown,
    onSave,
    onDelete,
}: {
    link: ChannelLink;
    first: boolean;
    last: boolean;
    onMoveUp: () => void;
    onMoveDown: () => void;
    onSave: (label: string, url: string) => void;
    onDelete: () => void;
}) {
    const [label, setLabel] = useState(link.label);
    const [url, setUrl] = useState(link.url);

    const save = () => {
        const trimmedLabel = label.trim();
        const trimmedUrl = url.trim();

        if (trimmedLabel === '' || trimmedUrl === '') {
            // Emptying a field is not an edit, it is a half-finished one. The
            // stored value comes back rather than being wiped.
            setLabel(link.label);
            setUrl(link.url);

            return;
        }

        if (trimmedLabel !== link.label || trimmedUrl !== link.url) {
            onSave(trimmedLabel, trimmedUrl);
        }
    };

    return (
        <li className="flex items-center gap-2 rounded-lg border p-2">
            <div className="flex shrink-0 flex-col">
                <button
                    type="button"
                    disabled={first}
                    onClick={onMoveUp}
                    aria-label={`${link.label} naar voren`}
                    className="rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none disabled:opacity-25"
                >
                    <ChevronUp className="size-3.5" />
                </button>
                <button
                    type="button"
                    disabled={last}
                    onClick={onMoveDown}
                    aria-label={`${link.label} naar achteren`}
                    className="rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none disabled:opacity-25"
                >
                    <ChevronDown className="size-3.5" />
                </button>
            </div>

            <Input
                value={label}
                maxLength={40}
                aria-label="Naam"
                onChange={(event) => setLabel(event.target.value)}
                onBlur={save}
                onKeyDown={(event) => event.key === 'Enter' && save()}
                className="w-40 shrink-0"
            />
            <Input
                value={url}
                type="url"
                aria-label="Adres"
                onChange={(event) => setUrl(event.target.value)}
                onBlur={save}
                onKeyDown={(event) => event.key === 'Enter' && save()}
            />

            <a
                href={link.url}
                target="_blank"
                rel="noopener noreferrer"
                title="Openen"
                aria-label={`${link.label} openen`}
                className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
            >
                <ExternalLink className="size-3.5" />
            </a>
            <button
                type="button"
                onClick={onDelete}
                aria-label={`${link.label} verwijderen`}
                className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:outline-none"
            >
                <Trash2 className="size-3.5" />
            </button>
        </li>
    );
}
