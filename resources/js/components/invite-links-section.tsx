import { Form, router } from '@inertiajs/react';
import { Check, Copy, Hash, Link2, Lock, X } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/chat/invite-links';

/** A channel a link may be pointed at. */
export interface InvitableChannel {
    id: number;
    name: string;
    isPrivate: boolean;
}

export interface InviteLink {
    id: number;
    /** The whole address, token and all — this is the thing you share. */
    url: string;
    roleLabel: string;
    isGuest: boolean;
    createdBy: string | null;
    uses: number;
    /** Null for as often as anybody likes. */
    maxUses: number | null;
    /** Null to keep working until somebody withdraws it. */
    expiresAt: string | null;
    state: 'usable' | 'expired' | 'revoked' | 'exhausted';
    channels: string[];
}

const EXPIRY_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    day: 'numeric',
    month: 'long',
});

const ROLES: { value: 'guest' | 'member'; label: string; hint: string }[] = [
    {
        value: 'member',
        label: 'Lid',
        hint: 'Hoort erbij. Vindt de openbare kanalen zelf en ziet wie er in de workspace zitten.',
    },
    {
        value: 'guest',
        label: 'Gast',
        hint: 'Iemand van buiten. Ziet alleen de kanalen die je aanvinkt.',
    },
];

/**
 * How long a new link stays good for. Days rather than a date: what you are
 * deciding is how long it may circulate, and a dropdown of that is one choice
 * instead of a date picker and a calculation.
 */
const VALIDITY: { value: string; label: string }[] = [
    { value: '1', label: '1 dag' },
    { value: '7', label: '7 dagen' },
    { value: '30', label: '30 dagen' },
    { value: '', label: 'Onbeperkt' },
];

/** Why a link is on the list but no longer lets anybody in. */
const DEAD: Record<string, string> = {
    expired: 'verlopen',
    revoked: 'ingetrokken',
    exhausted: 'opgebruikt',
};

function CopyButton({ url }: { url: string }) {
    const [copied, copy] = useClipboard();
    const isCopied = copied === url;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => void copy(url)}
            aria-label="Link kopiëren"
            title="Link kopiëren"
        >
            {isCopied ? (
                <Check className="size-3.5 text-emerald-600" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {isCopied ? 'Gekopieerd' : 'Kopiëren'}
        </Button>
    );
}

/**
 * Making and withdrawing links that anybody holding them may use.
 *
 * Sits beside the list of mailed invitations rather than in the chat: a link
 * you hand out is something you keep an eye on afterwards, and keeping an eye
 * on things is what this screen is for.
 */
export function InviteLinksSection({
    workspaceSlug,
    links,
    channels,
}: {
    workspaceSlug: string;
    links: InviteLink[];
    channels: InvitableChannel[];
}) {
    const [role, setRole] = useState<'guest' | 'member'>('member');
    const [picked, setPicked] = useState<number[]>([]);

    /*
     * The link waiting for a yes. Withdrawing cannot be undone and the link may
     * already be in somebody's mail, so it is worth one question — the same one
     * removing a member gets on the members screen.
     */
    const [pendingRevoke, setPendingRevoke] = useState<InviteLink | null>(null);

    const toggle = (id: number) =>
        setPicked((current) =>
            current.includes(id)
                ? current.filter((each) => each !== id)
                : [...current, id],
        );

    return (
        <div className="space-y-4">
            <Form
                action={store.url({ workspace: workspaceSlug })}
                method="post"
                options={{ preserveScroll: true }}
                onSuccess={() => setPicked([])}
                disableWhileProcessing
                className="space-y-4 rounded-lg border p-4"
            >
                {({ processing, errors }) => (
                    <>
                        {/*
                            One hidden field per ticked channel: the endpoint
                            takes channel_ids as an array, and the ticks live in
                            React state so the list can be shown for a guest and
                            hidden for a member without losing it.
                        */}
                        {role === 'guest' &&
                            picked.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="channel_ids[]"
                                    value={id}
                                />
                            ))}

                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                Wie er via deze link binnenkomt
                            </legend>
                            {ROLES.map((option) => (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                        role === option.value
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'hover:bg-muted/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="role"
                                        value={option.value}
                                        checked={role === option.value}
                                        onChange={() => setRole(option.value)}
                                        className="mt-0.5"
                                    />
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {option.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {option.hint}
                                        </span>
                                    </span>
                                </label>
                            ))}
                            <InputError message={errors.role} />
                        </fieldset>

                        {role === 'guest' && (
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Kanalen voor deze gast
                                </legend>

                                {channels.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Er zijn nog geen kanalen om iemand voor
                                        uit te nodigen.
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
                                                            toggle(channel.id)
                                                        }
                                                    />
                                                    {channel.isPrivate ? (
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
                            </fieldset>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="max_uses">
                                    Maximaal aantal keer te gebruiken
                                </Label>
                                <Input
                                    id="max_uses"
                                    name="max_uses"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    placeholder="Onbeperkt"
                                />
                                <InputError message={errors.max_uses} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="valid_for_days">
                                    Geldig gedurende
                                </Label>
                                <select
                                    id="valid_for_days"
                                    name="valid_for_days"
                                    defaultValue="7"
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {VALIDITY.map((option) => (
                                        <option
                                            key={option.label}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.valid_for_days} />
                            </div>
                        </div>

                        <Button type="submit">
                            {processing && <Spinner />}
                            <Link2 className="size-4" />
                            Link aanmaken
                        </Button>
                    </>
                )}
            </Form>

            {links.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <Link2 className="mx-auto size-6 text-muted-foreground" />
                    <p className="mt-3 text-sm font-medium">
                        Er zijn nog geen uitnodigingslinks
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Handig als je niet weet wie er precies binnenkomt — een
                        groep tegelijk, of een adres dat je niet hebt.
                    </p>
                </div>
            ) : (
                <ul className="divide-y rounded-lg border px-3">
                    {links.map((link) => (
                        <li
                            key={link.id}
                            className="flex flex-wrap items-center gap-3 py-3"
                        >
                            <span className="min-w-0 flex-1">
                                <span
                                    className={cn(
                                        'block truncate font-mono text-sm',
                                        link.state !== 'usable' &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {link.url}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {link.roleLabel}
                                    {link.createdBy &&
                                        ` · gemaakt door ${link.createdBy}`}
                                    {link.channels.length > 0 &&
                                        ' · ' +
                                            link.channels
                                                .map((name) => `#${name}`)
                                                .join(', ')}
                                </span>
                            </span>

                            <span className="shrink-0 text-xs text-muted-foreground">
                                {link.uses}
                                {link.maxUses === null
                                    ? 'x gebruikt'
                                    : ` van ${link.maxUses} gebruikt`}
                            </span>

                            <span
                                className={cn(
                                    'shrink-0 text-xs',
                                    link.state === 'usable'
                                        ? 'text-muted-foreground'
                                        : 'text-destructive',
                                )}
                            >
                                {link.state !== 'usable'
                                    ? DEAD[link.state]
                                    : link.expiresAt === null
                                      ? 'onbeperkt geldig'
                                      : `geldig tot ${EXPIRY_FORMAT.format(
                                            new Date(link.expiresAt),
                                        )}`}
                            </span>

                            {link.state === 'usable' && (
                                <CopyButton url={link.url} />
                            )}

                            {link.state !== 'revoked' && (
                                <button
                                    type="button"
                                    onClick={() => setPendingRevoke(link)}
                                    aria-label="Uitnodigingslink intrekken"
                                    title="Intrekken"
                                    className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <AlertDialog
                open={pendingRevoke !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setPendingRevoke(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Deze uitnodigingslink intrekken?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            De link werkt daarna niet meer, ook niet voor wie
                            hem al heeft. Wie er eerder mee binnenkwam, blijft
                            gewoon lid.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Annuleren</AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                const link = pendingRevoke;

                                if (link) {
                                    router.delete(
                                        destroy.url({
                                            workspace: workspaceSlug,
                                            invite_link: link.id,
                                        }),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            Intrekken
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
