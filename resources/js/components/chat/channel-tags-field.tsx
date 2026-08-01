import { X } from 'lucide-react';
import { useRef, useState } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

interface ChannelTagsFieldProps {
    /** The labels on this channel, in the order they are drawn. */
    value: string[];
    onChange: (next: string[]) => void;
    /** Every label already in use in the workspace, to suggest from. */
    suggestions: string[];
}

/** The same rule the server judges uniqueness on — see ChannelTag::slugFor. */
function slugFor(name: string): string {
    return name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/**
 * The labels on a channel: a row of chips and a field to add to it.
 *
 * A tag is created by being used rather than declared first, so this one field
 * does both — what you type is either an existing label or a new one, and from
 * here the difference does not matter. The suggestions exist so the choice
 * lands on an existing label when there is one: two channels tagged "klanten"
 * and "klant" are two lists where one was meant.
 */
export function ChannelTagsField({
    value,
    onChange,
    suggestions,
}: ChannelTagsFieldProps) {
    const [draft, setDraft] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    const taken = new Set(value.map(slugFor));

    const offered = suggestions
        .filter((suggestion) => !taken.has(slugFor(suggestion)))
        .filter((suggestion) =>
            draft.trim() === ''
                ? true
                : slugFor(suggestion).includes(slugFor(draft)),
        )
        .slice(0, 8);

    const add = (name: string) => {
        const trimmed = name.trim();

        // Nothing, or something the channel already carries under another
        // spelling: either way the field just clears rather than complaining.
        if (trimmed !== '' && !taken.has(slugFor(trimmed))) {
            onChange([...value, trimmed]);
        }

        setDraft('');
    };

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor="channel-tag">Tags</Label>

            {value.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {value.map((tag) => (
                        <span
                            key={tag}
                            className="inline-flex items-center gap-1 rounded-full border bg-muted/60 py-0.5 pr-1 pl-2.5 text-xs font-medium"
                        >
                            {tag}
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        value.filter((each) => each !== tag),
                                    )
                                }
                                aria-label={`${tag} weghalen`}
                                className="rounded-full p-0.5 text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            <Input
                id="channel-tag"
                ref={inputRef}
                value={draft}
                maxLength={40}
                placeholder="Typ een tag en druk op Enter"
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        // This field sits inside a dialog with a save button;
                        // Enter here means "add this tag", never "submit".
                        event.preventDefault();
                        add(draft);

                        return;
                    }

                    // Backspace on an empty field takes the last chip off, the
                    // way every tag field people have used before does.
                    if (
                        event.key === 'Backspace' &&
                        draft === '' &&
                        value.length > 0
                    ) {
                        onChange(value.slice(0, -1));
                    }
                }}
            />

            {offered.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    <span className="text-xs text-muted-foreground">
                        Al in gebruik:
                    </span>
                    {offered.map((suggestion) => (
                        <button
                            key={suggestion}
                            type="button"
                            onClick={() => {
                                add(suggestion);
                                inputRef.current?.focus();
                            }}
                            className={cn(
                                'rounded-full border px-2 py-0.5 text-xs text-muted-foreground transition-colors',
                                'hover:border-primary/40 hover:text-foreground focus-visible:ring-2 focus-visible:outline-none',
                            )}
                        >
                            {suggestion}
                        </button>
                    ))}
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                Tags horen bij de workspace, niet bij dit kanaal: dezelfde tag
                kan aan meerdere kanalen hangen. Een tag die nergens meer op zit
                verdwijnt vanzelf.
            </p>
        </div>
    );
}
