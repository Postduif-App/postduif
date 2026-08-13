import { Form, router } from '@inertiajs/react';
import { Check, Copy, Hash, Link2, Lock, X } from 'lucide-react';
import { useState } from 'react';

import { ChoiceText } from '@/components/choice-text';
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
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/chat/invite-links';
import type { ChatWorkspace } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

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

/**
 * How long a new link stays good for. Days rather than a date: what you are
 * deciding is how long it may circulate, and a dropdown of that is one choice
 * instead of a date picker and a calculation.
 */
const VALIDITY: { value: string; label: TranslationKey }[] = [
    { value: '1', label: 'panels.invites.validity_one_day' },
    { value: '7', label: 'panels.invites.validity_seven_days' },
    { value: '30', label: 'panels.invites.validity_thirty_days' },
    { value: '', label: 'panels.invites.validity_unlimited' },
];

/** Why a link is on the list but no longer lets anybody in. */
const DEAD: Record<string, TranslationKey> = {
    expired: 'panels.invites.dead_expired',
    revoked: 'panels.invites.dead_revoked',
    exhausted: 'panels.invites.dead_exhausted',
};

function CopyButton({ url }: { url: string }) {
    const { t } = useTranslate();
    const [copied, copy] = useClipboard();
    const isCopied = copied === url;

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => void copy(url)}
            aria-label={t('panels.invites.copy_link')}
            title={t('panels.invites.copy_link')}
        >
            {isCopied ? (
                <Check className="size-3.5 text-emerald-600" />
            ) : (
                <Copy className="size-3.5" />
            )}
            {isCopied ? t('panels.invites.copied') : t('panels.invites.copy')}
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
    roles,
}: {
    workspaceSlug: string;
    links: InviteLink[];
    channels: InvitableChannel[];
    /** The roles this member may hand out — a workspace writes its own. */
    roles: ChatWorkspace['invitableRoles'];
}) {
    const formats = useFormats();
    const { t } = useTranslate();

    /*
     * The role is posted by its own id, so the form cannot name one in advance.
     * It opens on the first role that is not from outside — a link you hand
     * around inside the company is the common case — and otherwise on the first
     * there is.
     */
    const [role, setRole] = useState<number>(
        () => (roles.find((one) => !one.isExternal) ?? roles[0])?.id ?? 0,
    );
    const chosen = roles.find((one) => one.id === role);
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
                        <input type="hidden" name="role" value={role} />

                        {chosen?.isExternal &&
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
                                {t('panels.invites.role_legend')}
                            </legend>
                            {roles.map((option) => (
                                <label
                                    key={option.id}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                        role === option.id
                                            ? 'border-primary/50 bg-primary/5'
                                            : 'hover:bg-muted/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="role-choice"
                                        value={option.id}
                                        checked={role === option.id}
                                        onChange={() => setRole(option.id)}
                                        className="mt-0.5"
                                    />
                                    {/*
                                        The role's own name, with the one line
                                        the application can say for certain
                                        about a role it did not name: whether
                                        the person is coming in from outside.
                                    */}
                                    <ChoiceText
                                        title={option.name}
                                        hint={t(
                                            option.isExternal
                                                ? 'panels.invites.role_guest_hint'
                                                : 'panels.invites.role_member_hint',
                                        )}
                                    />
                                </label>
                            ))}
                            <InputError message={errors.role} />
                        </fieldset>

                        {chosen?.isExternal && (
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('panels.invites.channels_legend')}
                                </legend>

                                {channels.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('panels.invites.no_channels')}
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
                                    {t('panels.invites.max_uses_label')}
                                </Label>
                                <Input
                                    id="max_uses"
                                    name="max_uses"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    placeholder={t(
                                        'panels.invites.max_uses_placeholder',
                                    )}
                                />
                                <InputError message={errors.max_uses} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="valid_for_days">
                                    {t('panels.invites.validity_label')}
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
                                            {t(option.label)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.valid_for_days} />
                            </div>
                        </div>

                        <Button type="submit">
                            {processing && <Spinner />}
                            <Link2 className="size-4" />
                            {t('panels.invites.submit')}
                        </Button>
                    </>
                )}
            </Form>

            {links.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <Link2 className="mx-auto size-6 text-muted-foreground" />
                    <p className="mt-3 text-sm font-medium">
                        {t('panels.invites.empty_title')}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('panels.invites.empty_hint')}
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
                                        ` · ${t('panels.invites.created_by', { name: link.createdBy })}`}
                                    {link.channels.length > 0 &&
                                        ' · ' +
                                            link.channels
                                                .map((name) => `#${name}`)
                                                .join(', ')}
                                </span>
                            </span>

                            <span className="shrink-0 text-xs text-muted-foreground">
                                {link.maxUses === null
                                    ? t('panels.invites.uses_open', {
                                          count: link.uses,
                                      })
                                    : t('panels.invites.uses_capped', {
                                          count: link.uses,
                                          max: link.maxUses,
                                      })}
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
                                    ? t(DEAD[link.state])
                                    : link.expiresAt === null
                                      ? t('panels.invites.unlimited_validity')
                                      : t('panels.invites.valid_until', {
                                            date: formats.date.format(
                                                new Date(link.expiresAt),
                                            ),
                                        })}
                            </span>

                            {link.state === 'usable' && (
                                <CopyButton url={link.url} />
                            )}

                            {link.state !== 'revoked' && (
                                <button
                                    type="button"
                                    onClick={() => setPendingRevoke(link)}
                                    aria-label={t('panels.invites.revoke')}
                                    title={t('panels.invites.revoke_short')}
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
                            {t('panels.invites.revoke_title')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('panels.invites.revoke_description')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('panels.invites.cancel')}
                        </AlertDialogCancel>
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
                            {t('panels.invites.revoke_confirm')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
