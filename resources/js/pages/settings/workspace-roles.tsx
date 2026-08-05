import { Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ChoiceText } from '@/components/choice-text';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/workspace/roles';

interface Role {
    id: number;
    key: string;
    name: string;
    isExternal: boolean;
    isSystem: boolean;
    /** How many people hold it, which decides what may still be changed. */
    holders: number;
    abilities: string[];
    /**
     * Whether this row may be touched at all. Worked out by the server: the
     * rule about reaching past your own role lives in one place, and this side
     * only renders the answer.
     */
    editable: boolean;
}

interface Ability {
    value: string;
    label: string;
    description: string;
    /** Whether the person reading this holds it, and so may hand it out. */
    held: boolean;
}

interface RolesProps {
    roles: Role[];
    abilities: Ability[];
    /** The name of the workspace these roles belong to, for the heading. */
    workspace: string;
}

/**
 * One role, with its rights.
 *
 * Saved as a whole rather than per tickbox. A role is a sentence — this name,
 * these rights — and saving half of it on every click would leave a moment
 * where somebody holds a role nobody wrote.
 */
function RoleCard({ role, abilities }: { role: Role; abilities: Ability[] }) {
    const { t, tChoice } = useTranslate();

    const [name, setName] = useState(role.name);
    const [held, setHeld] = useState<string[]>(role.abilities);
    const [external, setExternal] = useState(role.isExternal);

    const dirty =
        name !== role.name ||
        external !== role.isExternal ||
        held.length !== role.abilities.length ||
        held.some((ability) => !role.abilities.includes(ability));

    /*
     * Fixed once somebody holds the role, and always for the four that ship
     * with a workspace. Flipping it moves people across the line that decides
     * which channels exist for them, which is not something a tickbox on a page
     * about rights should do quietly.
     */
    const externalLocked = role.isSystem || role.holders > 0;

    const toggle = (ability: string) =>
        setHeld((current) =>
            current.includes(ability)
                ? current.filter((one) => one !== ability)
                : [...current, ability],
        );

    return (
        <div className="rounded-lg border p-4">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    disabled={!role.editable}
                    className="max-w-64"
                    aria-label={t('workspace_roles.name')}
                />

                {role.isSystem && (
                    <span className="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                        {t('workspace_roles.system')}
                    </span>
                )}

                {role.isExternal && (
                    <span className="rounded border border-amber-500/40 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400">
                        {t('workspace_roles.external')}
                    </span>
                )}

                <span className="text-xs text-muted-foreground">
                    {tChoice('workspace_roles.holders', role.holders)}
                </span>

                {!role.isSystem && role.editable && (
                    <button
                        type="button"
                        onClick={() =>
                            router.delete(destroy.url(role.id), {
                                preserveScroll: true,
                            })
                        }
                        aria-label={t('workspace_roles.delete')}
                        className="ml-auto text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                    </button>
                )}
            </div>

            {!role.editable && (
                <p className="mb-4 text-xs text-muted-foreground">
                    {t('workspace_roles.not_yours')}
                </p>
            )}

            <Label className="mb-2 block text-xs">
                {t('workspace_roles.abilities')}
            </Label>

            <ul className="m-0 mb-4 grid list-none gap-2 p-0 sm:grid-cols-2">
                {abilities.map((ability) => (
                    <li key={ability.value}>
                        <label
                            className={cn(
                                'flex items-start gap-2.5',
                                (!role.editable || !ability.held) &&
                                    'opacity-50',
                            )}
                        >
                            <Checkbox
                                checked={held.includes(ability.value)}
                                onCheckedChange={() => toggle(ability.value)}
                                /*
                                 * A right the reader does not hold is shown and
                                 * not offered. Hiding it would leave "why can I
                                 * not tick that" unanswered; the server refuses
                                 * it either way.
                                 */
                                disabled={!role.editable || !ability.held}
                                className="mt-0.5"
                            />
                            {/*
                                subtle: this list runs to every ability the
                                workspace has, and a bold line per row would be
                                the texture of the screen rather than emphasis
                                on anything.
                            */}
                            <ChoiceText
                                subtle
                                title={ability.label}
                                hint={ability.description}
                            />
                        </label>
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap items-center gap-3">
                <label
                    className={cn(
                        'flex items-center gap-2 text-sm',
                        (externalLocked || !role.editable) && 'opacity-50',
                    )}
                >
                    <Checkbox
                        checked={external}
                        onCheckedChange={(checked) => setExternal(!!checked)}
                        disabled={externalLocked || !role.editable}
                    />
                    {t('workspace_roles.external')}
                </label>

                <p className="max-w-[46ch] text-xs text-muted-foreground">
                    {externalLocked
                        ? t('workspace_roles.external_locked')
                        : t('workspace_roles.external_hint')}
                </p>

                {role.editable && (
                    <Button
                        className="ml-auto"
                        disabled={!dirty}
                        onClick={() =>
                            router.patch(
                                update.url(role.id),
                                {
                                    name,
                                    is_external: external,
                                    abilities: held,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('workspace_roles.save')}
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function WorkspaceRoles({
    roles,
    abilities,
    workspace,
}: RolesProps) {
    const { t } = useTranslate();
    const [name, setName] = useState('');

    return (
        <>
            <Head title={t('workspace_roles.title')} />

            {/*
                Was the one settings screen using Heading's large variant, so
                its title sat a size bigger than the same title on every
                neighbouring page.
            */}
            <SettingsSection
                title={t('workspace_roles.title')}
                description={t('workspace_roles.description', {
                    workspace,
                })}
            >
                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('workspace_roles.explanation')}
                </p>

                <div className="space-y-4">
                    {roles.map((role) => (
                        <RoleCard
                            key={role.id}
                            role={role}
                            abilities={abilities}
                        />
                    ))}
                </div>

                <div className="space-y-3 rounded-lg border border-dashed p-4">
                    <Label htmlFor="new-role">{t('workspace_roles.new')}</Label>

                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            id="new-role"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder={t('workspace_roles.new_name')}
                            className="max-w-xs"
                        />

                        <Button
                            disabled={name.trim() === ''}
                            onClick={() =>
                                router.post(
                                    store.url(),
                                    {
                                        name,
                                        is_external: false,
                                        // Nothing ticked. A new role starts
                                        // holding nothing and is filled in
                                        // deliberately, which is the safe
                                        // direction for a thing that hands out
                                        // rights.
                                        abilities: [],
                                    },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setName(''),
                                    },
                                )
                            }
                        >
                            <Plus className="size-4" />
                            {t('workspace_roles.create')}
                        </Button>
                    </div>
                </div>
            </SettingsSection>
        </>
    );
}
