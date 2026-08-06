import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Plus,
    Trash2,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslate } from '@/hooks/use-translate';
import { destroy, reorder, store, update } from '@/routes/chat/channels/links';
import type { ActiveChannel, ChannelLink, ChatWorkspace } from '@/types/chat';

/**
 * What the picker calls "a web address".
 *
 * A sentinel rather than an empty value, because a Radix SelectItem may not
 * have one — an empty string is how it says "nothing is chosen", and this is a
 * choice like any other.
 */
const URL_TARGET = 'url';

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
    const { t } = useTranslate();
    const [label, setLabel] = useState('');
    const [url, setUrl] = useState('');
    /**
     * The workflow the new button will start, or '' for one that opens a URL.
     *
     * One control deciding between the two rather than two forms side by side:
     * a button does exactly one of them, and two forms would let somebody fill
     * in both and then be told off for it.
     */
    const [workflowId, setWorkflowId] = useState('');
    const [busy, setBusy] = useState(false);

    const target = { workspace: workspace.slug, channel: channel.id };
    const links = channel.links;
    const workflows = channel.buttonWorkflows;
    const startsWorkflow = workflowId !== '';

    const add = () => {
        const ready = startsWorkflow ? true : url.trim() !== '';

        if (label.trim() === '' || !ready || busy) {
            return;
        }

        setBusy(true);
        router.post(
            store.url(target),
            {
                label: label.trim(),
                // Only the one that applies is sent. Sending the other as an
                // empty string would be a button pointing at two things, which
                // the request refuses and the database refuses after it.
                ...(startsWorkflow
                    ? { workflow_id: Number(workflowId) }
                    : { url: url.trim() }),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setLabel('');
                    setUrl('');
                    setWorkflowId('');
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
                <h3 className="text-sm font-medium">
                    {t('channels.settings.tabs.links')}
                </h3>
                <p className="text-xs text-muted-foreground">
                    {t('chat_ui.links.explanation')}
                </p>
            </div>

            {links.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                    {t('chat_ui.links.empty')}
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
                                    // No url key at all for a workflow button:
                                    // sending null would read as "point this
                                    // nowhere", and the row must keep pointing
                                    // at what it points at.
                                    url === null ? { label } : { label, url },
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
                    {t('chat_ui.links.new')}
                </Label>
                <div className="flex gap-2">
                    <Input
                        id="new-link-label"
                        value={label}
                        maxLength={40}
                        placeholder={t('channels.fields.name')}
                        onChange={(event) => setLabel(event.target.value)}
                        className="w-40 shrink-0"
                    />

                    {/*
                        Only offered where there is something to offer. A
                        workspace with no button workflows would otherwise get a
                        picker whose only entry is "a web address", which is a
                        question with one answer.
                    */}
                    {workflows.length > 0 && (
                        <Select
                            value={workflowId === '' ? URL_TARGET : workflowId}
                            onValueChange={(value) =>
                                setWorkflowId(value === URL_TARGET ? '' : value)
                            }
                        >
                            <SelectTrigger
                                aria-label={t('chat_ui.links.target')}
                                className="w-44 shrink-0"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={URL_TARGET}>
                                    {t('chat_ui.links.target_url')}
                                </SelectItem>
                                {workflows.map((workflow) => (
                                    <SelectItem
                                        key={workflow.id}
                                        value={String(workflow.id)}
                                    >
                                        {workflow.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    {/*
                        The address disappears once a workflow is chosen rather
                        than being greyed out: an empty box that cannot be typed
                        in reads as something broken, and there is nothing left
                        to say about where this button goes.
                    */}
                    {!startsWorkflow && (
                        <Input
                            value={url}
                            type="url"
                            placeholder="https://"
                            onChange={(event) => setUrl(event.target.value)}
                            aria-label={t('chat_ui.links.address')}
                        />
                    )}

                    <Button
                        size="icon"
                        className="ml-auto shrink-0"
                        disabled={
                            busy ||
                            label.trim() === '' ||
                            (!startsWorkflow && url.trim() === '')
                        }
                        onClick={add}
                        aria-label={t('chat_ui.links.add')}
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
    /** The address, or null for a button that starts a workflow. */
    onSave: (label: string, url: string | null) => void;
    onDelete: () => void;
}) {
    const { t } = useTranslate();
    const [label, setLabel] = useState(link.label);
    const [url, setUrl] = useState(link.url ?? '');

    const save = () => {
        const trimmedLabel = label.trim();
        const trimmedUrl = url.trim();

        /*
         * A workflow button has no address to correct — what it starts was
         * decided when it was made. Renaming it is still an edit, so the label
         * goes on its own.
         */
        if (link.workflowId !== null) {
            if (trimmedLabel === '') {
                setLabel(link.label);

                return;
            }

            if (trimmedLabel !== link.label) {
                onSave(trimmedLabel, null);
            }

            return;
        }

        if (trimmedLabel === '' || trimmedUrl === '') {
            // Emptying a field is not an edit, it is a half-finished one. The
            // stored value comes back rather than being wiped.
            setLabel(link.label);
            setUrl(link.url ?? '');

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
                    aria-label={t('chat_ui.links.move_up', {
                        label: link.label,
                    })}
                    className="rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none disabled:opacity-25"
                >
                    <ChevronUp className="size-3.5" />
                </button>
                <button
                    type="button"
                    disabled={last}
                    onClick={onMoveDown}
                    aria-label={t('chat_ui.links.move_down', {
                        label: link.label,
                    })}
                    className="rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:outline-none disabled:opacity-25"
                >
                    <ChevronDown className="size-3.5" />
                </button>
            </div>

            <Input
                value={label}
                maxLength={40}
                aria-label={t('channels.fields.name')}
                onChange={(event) => setLabel(event.target.value)}
                onBlur={save}
                onKeyDown={(event) => event.key === 'Enter' && save()}
                className="w-40 shrink-0"
            />
            {link.workflowId === null ? (
                <>
                    <Input
                        value={url}
                        type="url"
                        aria-label={t('chat_ui.links.address')}
                        onChange={(event) => setUrl(event.target.value)}
                        onBlur={save}
                        onKeyDown={(event) => event.key === 'Enter' && save()}
                    />

                    <a
                        href={link.url ?? undefined}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={t('chat_ui.links.open')}
                        aria-label={t('chat_ui.links.open_named', {
                            label: link.label,
                        })}
                        className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <ExternalLink className="size-3.5" />
                    </a>
                </>
            ) : (
                /*
                    Text rather than a second picker. Pointing a button at
                    another workflow is rare enough to be worth doing by
                    removing it and adding the one you meant, and a picker here
                    would put "which workflow" in two places at once.
                */
                <p className="flex min-w-0 flex-1 items-center gap-1.5 text-xs text-muted-foreground">
                    <Zap className="size-3.5 shrink-0" />
                    <span className="truncate">
                        {link.workflowName ?? t('chat_ui.links.workflow_gone')}
                    </span>
                </p>
            )}
            <button
                type="button"
                onClick={onDelete}
                aria-label={t('chat_ui.links.remove', { label: link.label })}
                className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:outline-none"
            >
                <Trash2 className="size-3.5" />
            </button>
        </li>
    );
}
