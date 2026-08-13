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
 * One role in the list on the left.
 *
 * Says only what somebody picks a role by: its name, and how many people are
 * standing in it. What it may do is the other half of the screen — putting a
 * summary of the rights here as well would mean saying the same thing twice and
 * disagreeing with itself the moment a tickbox moves.
 */
function RoleRow({
    role,
    chosen,
    onChoose,
}: {
    role: Role;
    chosen: boolean;
    onChoose: () => void;
}) {
    const { t, tChoice } = useTranslate();

    return (
        <li>
            <button
                type="button"
                onClick={onChoose}
                aria-current={chosen}
                className={cn(
                    'w-full rounded-md px-2.5 py-2 text-start transition-colors hover:bg-muted/60',
                    chosen && 'bg-muted hover:bg-muted',
                )}
            >
                <span className="flex items-center gap-2">
                    <span className="min-w-0 truncate text-sm font-medium">
                        {role.name}
                    </span>

                    {role.isExternal && (
                        // Only this one badge survives into the list. Whether a
                        // role is from outside decides which channels exist for
                        // the people in it, which is the one thing worth
                        // knowing before you have clicked; "meegeleverd" is
                        // trivia until you are already looking at the role.
                        <span className="shrink-0 rounded border border-amber-500/40 px-1.5 text-[0.6875rem] text-amber-700 dark:text-amber-400">
                            {t('workspace_roles.external')}
                        </span>
                    )}
                </span>

                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {tChoice('workspace_roles.holders', role.holders)}
                </span>
            </button>
        </li>
    );
}

/**
 * One role, with its rights.
 *
 * Saved as a whole rather than per tickbox. A role is a sentence — this name,
 * these rights — and saving half of it on every click would leave a moment
 * where somebody holds a role nobody wrote.
 *
 * Its state is seeded from the props once, so the page above it mounts a fresh
 * one per role rather than reusing this one. See the `key` where it is rendered.
 */
function RoleEditor({ role, abilities }: { role: Role; abilities: Ability[] }) {
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
        <div className="min-w-0 rounded-lg border p-5">
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

            {/*
                Two columns from `xl` up, and one below it. A right is a title
                and a sentence explaining it, which wants a good 30ch to itself:
                below that width two columns turn every row into three wrapped
                lines, which is exactly the wall of text the split was meant to
                undo. Above it the catalogue would otherwise run a screen and a
                half in a single narrow strip down the middle of a wide page.
            */}
            <ul className="m-0 mb-4 grid list-none gap-x-8 gap-y-3 p-0 xl:grid-cols-2">
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

            <div className="flex flex-wrap items-center gap-3 border-t pt-4">
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
    const [chosenId, setChosenId] = useState<number | null>(
        roles[0]?.id ?? null,
    );

    /*
     * Which role the right-hand side is showing, worked out rather than stored.
     * A role can leave under this screen's feet — somebody deletes the one they
     * are looking at — and an id kept in state would then point at nothing.
     * Falling back to the first role means the panel is never empty while there
     * are roles, without a second piece of state to keep in step.
     */
    const chosen = roles.find((role) => role.id === chosenId) ?? roles[0];

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

                {/*
                    Split rather than a stack of cards. Every role carries the
                    whole catalogue of rights, so a workspace with eight of them
                    used to be eight near-identical blocks of tickboxes — a page
                    you scroll past rather than read. Picking one first makes
                    the question on screen "wat mag deze rol" instead of "welke
                    van deze vijftig vinkjes hoort bij wie".
                */}
                <div className="grid items-start gap-6 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-8">
                    {/*
                        Pinned while the rights scroll past. The catalogue on the
                        right is longer than a screen, and a role list that
                        scrolls away with it turns "look at the next role" into a
                        scroll back up to find the list again.
                    */}
                    <div className="space-y-4 lg:sticky lg:top-4">
                        <ul
                            aria-label={t('workspace_roles.title')}
                            className="m-0 list-none space-y-0.5 p-0"
                        >
                            {roles.map((role) => (
                                <RoleRow
                                    key={role.id}
                                    role={role}
                                    chosen={role.id === chosen?.id}
                                    onChoose={() => setChosenId(role.id)}
                                />
                            ))}
                        </ul>

                        <div className="space-y-3 rounded-lg border border-dashed p-3">
                            <Label htmlFor="new-role" className="text-xs">
                                {t('workspace_roles.new')}
                            </Label>

                            <Input
                                id="new-role"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder={t('workspace_roles.new_name')}
                            />

                            <Button
                                size="sm"
                                className="w-full"
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
                                            // direction for a thing that hands
                                            // out rights.
                                            abilities: [],
                                        },
                                        {
                                            preserveScroll: true,
                                            onSuccess: (page) => {
                                                setName('');

                                                /*
                                                 * Straight to the new role. It
                                                 * was created empty, so leaving
                                                 * the reader on whichever role
                                                 * they had open would hide the
                                                 * one thing that still has to
                                                 * happen: ticking what it may
                                                 * do. It arrives last, because
                                                 * a new role starts at the
                                                 * bottom of the order.
                                                 */
                                                const fresh = (
                                                    page.props
                                                        .roles as unknown as Role[]
                                                ).at(-1);

                                                if (fresh) {
                                                    setChosenId(fresh.id);
                                                }
                                            },
                                        },
                                    )
                                }
                            >
                                <Plus className="size-4" />
                                {t('workspace_roles.create')}
                            </Button>
                        </div>
                    </div>

                    {chosen && (
                        /*
                         * Keyed on the role, so picking another one mounts a
                         * fresh editor instead of reusing this one. The name
                         * and the tickboxes are state seeded from the props,
                         * and without the key React would keep the state of the
                         * role you just left — half-finished edits to one role
                         * would appear under the name of the next.
                         */
                        <RoleEditor
                            key={chosen.id}
                            role={chosen}
                            abilities={abilities}
                        />
                    )}
                </div>
            </SettingsSection>
        </>
    );
}
