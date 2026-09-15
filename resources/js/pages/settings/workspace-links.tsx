import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';
import { destroy, reorder, store, update } from '@/routes/workspace/links';

interface WorkspaceRole {
    id: number;
    name: string;
    /** Whether somebody in this role is here from another company. */
    isExternal: boolean;
}

interface WorkspaceLink {
    id: number;
    label: string;
    url: string;
    emoji: string | null;
    /** The roles this button is drawn for. Empty means nobody. */
    roleIds: number[];
}

interface WorkspaceLinksProps {
    links: WorkspaceLink[];
    roles: WorkspaceRole[];
    /** What the endpoint stops at, so the screen can say so before it refuses. */
    maxLinks: number;
    workspace: string;
}

/**
 * The form for one button, whether it exists yet or not.
 *
 * One component for both, like the status rules screen: adding and changing a
 * button are the same six fields, and a second copy of them is how the two
 * start disagreeing about which are required.
 */
function LinkForm({
    link,
    roles,
    onDone,
    onCancel,
}: {
    /** The button being changed, or null when this is a new one. */
    link: WorkspaceLink | null;
    roles: WorkspaceRole[];
    onDone: () => void;
    onCancel?: () => void;
}) {
    const { t } = useTranslate();

    const [label, setLabel] = useState(link?.label ?? '');
    const [url, setUrl] = useState(link?.url ?? '');
    const [emoji, setEmoji] = useState(link?.emoji ?? '');
    /*
        A new button starts with every role ticked.

        The empty set means nobody — see the workspace_link_role migration —
        which is the right way round for a mistake but the wrong way round for a
        starting point: somebody adding a shortcut to the planning means
        everybody, and having to tick five boxes to say so reads as the software
        arguing with them.
    */
    const [roleIds, setRoleIds] = useState<number[]>(
        link?.roleIds ?? roles.map((role) => role.id),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const toggleRole = (id: number) =>
        setRoleIds((current) =>
            current.includes(id)
                ? current.filter((held) => held !== id)
                : [...current, id],
        );

    const submit = () => {
        setProcessing(true);

        const payload = {
            label,
            url,
            emoji: emoji || null,
            role_ids: roleIds,
        };

        const options = {
            preserveScroll: true,
            onSuccess: onDone,
            onError: setErrors,
            onFinish: () => setProcessing(false),
        };

        if (link === null) {
            router.post(store.url(), payload, options);
        } else {
            router.patch(update.url(link.id), payload, options);
        }
    };

    return (
        <div
            className={
                link === null
                    ? // Dashed while it is where something new comes from,
                      // solid once it is a thing in the list being changed.
                      'space-y-4 rounded-lg border border-dashed p-4'
                    : 'space-y-4 rounded-lg border p-4'
            }
        >
            <div className="grid gap-4 sm:grid-cols-[4rem_1fr]">
                <div className="grid gap-2">
                    <Label htmlFor="link-emoji">
                        {t('workspace_links.emoji')}
                    </Label>
                    <Input
                        id="link-emoji"
                        value={emoji}
                        onChange={(event) => setEmoji(event.target.value)}
                        placeholder="🔗"
                    />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="link-label">
                        {t('workspace_links.label')}
                    </Label>
                    <Input
                        id="link-label"
                        value={label}
                        maxLength={40}
                        onChange={(event) => setLabel(event.target.value)}
                        placeholder={t('workspace_links.label_placeholder')}
                    />
                    <InputError message={errors.label} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="link-url">{t('workspace_links.url')}</Label>
                <Input
                    id="link-url"
                    type="url"
                    value={url}
                    onChange={(event) => setUrl(event.target.value)}
                    placeholder={t('workspace_links.url_placeholder')}
                />
                <p className="text-xs text-muted-foreground">
                    {t('workspace_links.url_hint')}
                </p>
                <InputError message={errors.url} />
            </div>

            <div className="grid gap-2">
                <Label>{t('workspace_links.roles')}</Label>
                <p className="text-xs text-muted-foreground">
                    {t('workspace_links.roles_hint')}
                </p>

                <div className="flex flex-wrap gap-x-6 gap-y-2">
                    {roles.map((role) => (
                        <label
                            key={role.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={roleIds.includes(role.id)}
                                onCheckedChange={() => toggleRole(role.id)}
                            />
                            <span>{role.name}</span>
                            {/*
                                Said out loud rather than left to the name. A
                                workspace writes its own roles, so "Leverancier"
                                does not announce that the people in it are here
                                from another company — and that is exactly the
                                thing to be sure of before ticking the box.
                            */}
                            {role.isExternal && (
                                <span className="text-xs text-muted-foreground">
                                    {t('workspace_links.roles_external')}
                                </span>
                            )}
                        </label>
                    ))}
                </div>

                {roleIds.length === 0 && (
                    <p className="text-xs text-muted-foreground">
                        {t('workspace_links.roles_none')}
                    </p>
                )}

                <InputError message={errors.role_ids} />
            </div>

            <div className="flex items-center gap-2">
                <Button type="button" onClick={submit} disabled={processing}>
                    {t(
                        link === null
                            ? processing
                                ? 'workspace_links.adding'
                                : 'workspace_links.add'
                            : 'workspace_links.save',
                    )}
                </Button>

                {onCancel && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onCancel}
                        disabled={processing}
                    >
                        {t('workspace_links.cancel')}
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function WorkspaceLinksPage({
    links,
    roles,
    maxLinks,
    workspace,
}: WorkspaceLinksProps) {
    const { t } = useTranslate();

    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [removing, setRemoving] = useState<WorkspaceLink | null>(null);

    const full = links.length >= maxLinks;

    /*
        The whole order at once rather than a move-one endpoint: order is a
        property of the list, and two quick clicks sent separately could arrive
        the wrong way round. The same shape as the status rules screen.
    */
    const move = (index: number, direction: -1 | 1) => {
        const next = [...links];
        const target = index + direction;

        if (target < 0 || target >= next.length) {
            return;
        }

        [next[index], next[target]] = [next[target], next[index]];

        router.put(
            reorder.url(),
            { ids: next.map((link) => link.id) },
            { preserveScroll: true },
        );
    };

    /** The roles a button names, by name, for the row that summarises it. */
    const audience = (link: WorkspaceLink) => {
        if (link.roleIds.length === 0) {
            return t('workspace_links.roles_none');
        }

        if (link.roleIds.length === roles.length) {
            return t('workspace_links.roles_all');
        }

        return roles
            .filter((role) => link.roleIds.includes(role.id))
            .map((role) => role.name)
            .join(', ');
    };

    return (
        <>
            <Head title={t('workspace_links.title')} />

            <SettingsSection
                title={t('workspace_links.title')}
                description={t('workspace_links.description', { workspace })}
            >
                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('workspace_links.explanation')}
                </p>

                {links.length === 0 && !adding ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <ExternalLink className="mx-auto size-6 text-muted-foreground" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            {t('workspace_links.empty')}
                        </p>
                    </div>
                ) : (
                    <ul className="space-y-2">
                        {links.map((link, index) =>
                            editing === link.id ? (
                                <li key={link.id}>
                                    <LinkForm
                                        link={link}
                                        roles={roles}
                                        onDone={() => setEditing(null)}
                                        onCancel={() => setEditing(null)}
                                    />
                                </li>
                            ) : (
                                <li
                                    key={link.id}
                                    className="flex items-center gap-3 rounded-lg border p-3"
                                >
                                    <div className="flex flex-col">
                                        <button
                                            type="button"
                                            onClick={() => move(index, -1)}
                                            disabled={index === 0}
                                            aria-label={t(
                                                'workspace_links.move_up',
                                            )}
                                            className="text-muted-foreground disabled:opacity-30"
                                        >
                                            <ArrowUp className="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => move(index, 1)}
                                            disabled={
                                                index === links.length - 1
                                            }
                                            aria-label={t(
                                                'workspace_links.move_down',
                                            )}
                                            className="text-muted-foreground disabled:opacity-30"
                                        >
                                            <ArrowDown className="size-4" />
                                        </button>
                                    </div>

                                    <span
                                        aria-hidden
                                        className="w-5 shrink-0 text-center"
                                    >
                                        {link.emoji ?? (
                                            <ExternalLink className="size-4 text-muted-foreground" />
                                        )}
                                    </span>

                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {link.label}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {link.url}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {audience(link)}
                                        </p>
                                    </div>

                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setEditing(link.id)}
                                    >
                                        {t('workspace_links.edit')}
                                    </Button>

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t(
                                            'workspace_links.delete_question',
                                            { label: link.label },
                                        )}
                                        onClick={() => setRemoving(link)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </li>
                            ),
                        )}
                    </ul>
                )}

                {adding ? (
                    <LinkForm
                        link={null}
                        roles={roles}
                        onDone={() => setAdding(false)}
                        onCancel={() => setAdding(false)}
                    />
                ) : (
                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            onClick={() => setAdding(true)}
                            disabled={full}
                        >
                            <Plus className="size-4" />
                            {t('workspace_links.add')}
                        </Button>

                        {full && (
                            <p className="text-sm text-muted-foreground">
                                {t('workspace_links.too_many', {
                                    count: maxLinks,
                                })}
                            </p>
                        )}
                    </div>
                )}
            </SettingsSection>

            <AlertDialog
                open={removing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('workspace_links.delete_question', {
                                label: removing?.label ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('workspace_links.delete_explanation')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>
                            {t('workspace_links.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                if (removing !== null) {
                                    router.delete(destroy.url(removing.id), {
                                        preserveScroll: true,
                                    });
                                }

                                setRemoving(null);
                            }}
                        >
                            {t('workspace_links.delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
