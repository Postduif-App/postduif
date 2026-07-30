import { router } from '@inertiajs/react';
import { Hash, Lock, MessageSquare } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { search, show } from '@/routes/chat';
import type { ChatWorkspace, SearchHit } from '@/types/chat';

interface SearchDialogProps {
    workspace: ChatWorkspace;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

const DEBOUNCE_MS = 200;

export function SearchDialog({
    workspace,
    open,
    onOpenChange,
}: SearchDialogProps) {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<SearchHit[]>([]);
    const [loading, setLoading] = useState(false);

    const trimmed = query.trim();
    // Derived rather than stored: an empty query has no results by definition,
    // so there is nothing to synchronise back into state.
    const results = trimmed === '' ? [] : hits;

    useEffect(() => {
        if (!open || trimmed === '') {
            return;
        }

        // Abort the in-flight request on every keystroke so a slow early query
        // cannot overwrite the results of a later one.
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    search.url(workspace.slug, { query: { q: trimmed } }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();
                setHits(payload.results ?? []);
            } catch (error) {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setHits([]);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, trimmed, workspace.slug]);

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Zoeken"
            description="Zoek door alle berichten die je mag lezen"
            shouldFilter={false}
        >
            <CommandInput
                placeholder="Zoek in berichten…"
                value={query}
                onValueChange={setQuery}
            />
            <CommandList>
                {!loading && trimmed !== '' && results.length === 0 && (
                    <CommandEmpty>Geen berichten gevonden.</CommandEmpty>
                )}

                {results.length > 0 && (
                    <CommandGroup heading={`${results.length} resultaten`}>
                        {results.map((hit) => (
                            <CommandItem
                                key={hit.id}
                                value={hit.id}
                                onSelect={() => {
                                    onOpenChange(false);
                                    router.visit(
                                        show({
                                            workspace: workspace.slug,
                                            channel: hit.channel.id,
                                        }),
                                    );
                                }}
                                className="flex flex-col items-start gap-0.5"
                            >
                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    {hit.channel.type === 'dm' ? (
                                        <MessageSquare className="size-3" />
                                    ) : hit.channel.type === 'private' ? (
                                        <Lock className="size-3" />
                                    ) : (
                                        <Hash className="size-3" />
                                    )}
                                    {hit.channel.name ?? 'Direct bericht'} ·{' '}
                                    {hit.author}
                                </span>
                                <span className="line-clamp-2 text-sm">
                                    {hit.body}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}
            </CommandList>
        </CommandDialog>
    );
}
