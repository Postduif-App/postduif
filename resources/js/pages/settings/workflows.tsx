import { Head, Link, router } from '@inertiajs/react';
import { Check, History, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
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
import { TRIGGER_GLYPH, TRIGGER_GLYPHS } from '@/components/workflow-node';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    destroy,
    edit,
    runs as runsRoute,
    store,
    toggle,
} from '@/routes/workflows';

/**
 * A workflow as this screen needs it: a name, what sets it off, how big it is,
 * and when it last went off.
 *
 * Not its steps. Those belong to the builder, which is a screen of its own —
 * sending every step of every workflow to a list that draws a name and a count
 * is a page that gets slower each time somebody writes another one.
 */
interface Workflow {
    id: number;
    name: string;
    description: string | null;
    triggerType: string;
    enabled: boolean;
    owner: string | null;
    stepCount: number;
    /** Null when it has not run — or not within the fortnight runs are kept. */
    lastRunAt: string | null;
}

interface Described {
    key: string;
    label: string;
    description: string;
}

interface WorkflowsProps {
    workflows: Workflow[];
    triggers: Described[];
}

/**
 * One workflow as a row.
 *
 * The name is the way in, and everything beside it — on or off, the history,
 * the wastebasket — is something that can be done without opening it. Those
 * three are the reason the row is not simply one big link.
 */
function WorkflowRow({
    workflow,
    triggers,
}: {
    workflow: Workflow;
    triggers: Described[];
}) {
    const { t } = useTranslate();
    const formats = useFormats();

    const trigger = triggers.find((one) => one.key === workflow.triggerType);
    const Glyph = TRIGGER_GLYPHS[workflow.triggerType] ?? TRIGGER_GLYPH;

    return (
        <tr
            className={cn(
                'border-t',
                /*
                 * A workflow that is off is not broken and not a warning — it
                 * simply is not doing anything, which is what the muted
                 * treatment says everywhere else on these screens.
                 */
                !workflow.enabled && 'text-muted-foreground',
            )}
        >
            <td className="px-3 py-2">
                <div className="flex items-center gap-1.5">
                    <Glyph className="size-3.5 shrink-0 opacity-60" />
                    <Link
                        href={edit.url(workflow.id)}
                        className="font-medium hover:underline"
                    >
                        {workflow.name}
                    </Link>
                </div>

                {workflow.description && (
                    <p className="truncate text-xs text-muted-foreground">
                        {workflow.description}
                    </p>
                )}

                {/* What the column that steps aside was carrying. */}
                <p className="mt-1 text-xs text-muted-foreground lg:hidden">
                    {trigger?.label ?? workflow.triggerType}
                </p>
            </td>

            <td className="hidden px-3 py-2 text-sm lg:table-cell">
                {trigger?.label ?? workflow.triggerType}
            </td>

            <td className="px-3 py-2 text-sm whitespace-nowrap tabular-nums">
                {workflow.stepCount}
            </td>

            <td className="px-3 py-2 text-sm whitespace-nowrap">
                {workflow.lastRunAt
                    ? formats.shortDateTime.format(new Date(workflow.lastRunAt))
                    : t('settings.workflows.never_run')}
            </td>

            <td className="px-3 py-2">
                <label className="flex items-center gap-2 text-xs whitespace-nowrap">
                    <input
                        type="checkbox"
                        checked={workflow.enabled}
                        onChange={(event) =>
                            router.patch(
                                toggle.url(workflow.id),
                                { enabled: event.target.checked },
                                { preserveScroll: true },
                            )
                        }
                    />
                    {workflow.enabled
                        ? t('settings.workflows.on')
                        : t('settings.workflows.off')}
                </label>
            </td>

            <td className="px-3 py-2">
                <div className="flex items-center justify-end gap-3">
                    <Link
                        href={runsRoute.url(workflow.id)}
                        className="text-muted-foreground transition-colors hover:text-foreground"
                        aria-label={t('settings.workflows.history')}
                    >
                        <History className="size-4" />
                    </Link>

                    <button
                        type="button"
                        onClick={() =>
                            router.delete(destroy.url(workflow.id), {
                                preserveScroll: true,
                            })
                        }
                        aria-label={t('settings.workflows.delete')}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

export default function Workflows({ workflows, triggers }: WorkflowsProps) {
    const { t } = useTranslate();
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [triggerType, setTriggerType] = useState(triggers[0]?.key ?? '');

    return (
        <>
            <Head title={t('settings.workflows.title')} />

            <SettingsSection
                title={t('settings.workflows.title')}
                description={t('settings.workflows.description')}
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        <Plus className="size-4" />
                        {t('settings.workflows.new')}
                    </Button>
                }
            >
                {/*
                    The table scrolls inside its own box rather than pushing the
                    page sideways, the same choice the channel and member lists
                    make. Below `lg` it is a narrower table rather than the same
                    one behind a scrollbar: the trigger steps aside and reappears
                    under the name, which is worth more than dragging a table
                    sideways to read one word.
                */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-xl border-collapse lg:min-w-2xl">
                        <thead>
                            <tr className="bg-muted/40 text-xs font-medium text-muted-foreground">
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('settings.workflows.column_name')}
                                </th>
                                <th
                                    scope="col"
                                    className="hidden px-3 py-2 text-left lg:table-cell"
                                >
                                    {t('settings.workflows.column_trigger')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('settings.workflows.column_steps')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('settings.workflows.column_last_run')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('settings.workflows.column_state')}
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">
                                        {t('settings.workflows.column_actions')}
                                    </span>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {workflows.map((workflow) => (
                                <WorkflowRow
                                    key={workflow.id}
                                    workflow={workflow}
                                    triggers={triggers}
                                />
                            ))}

                            {workflows.length === 0 && (
                                <tr className="border-t">
                                    <td
                                        colSpan={6}
                                        className="px-3 py-6 text-center text-sm text-muted-foreground"
                                    >
                                        {t('settings.workflows.none')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </SettingsSection>

            {/*
                A new workflow is two questions — what it is called and what sets
                it off — and everything after that belongs to the builder. Small
                enough for a modal, and a modal keeps the list itself a list
                rather than a list with a form stuck to the bottom of it.
            */}
            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('settings.workflows.new')}</DialogTitle>
                        <DialogDescription>
                            {/*
                                A new workflow arrives switched off and opens
                                straight into the builder — saying so here beats
                                leaving somebody to wonder, once they are on that
                                screen, why nothing has happened yet.
                            */}
                            {t('settings.workflows.created_off')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-workflow">
                                {t('settings.workflows.name')}
                            </Label>
                            <Input
                                id="new-workflow"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder={t('settings.workflows.name')}
                                autoFocus
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="new-workflow-trigger">
                                {t('settings.workflows.trigger')}
                            </Label>

                            {/*
                                A list you can type in rather than a dropdown to
                                scroll: a workspace is offered some thirty
                                triggers, and finding "als een contract getekend
                                is" among the other seven contract triggers by
                                eye is slower than typing "getekend".

                                The search box is in the open rather than behind
                                a click. A popover inside a dialog is two layers
                                arguing over focus, and this is the same cmdk
                                list the emoji picker uses — which is where the
                                pattern already lives in this app.

                                Nothing is bound to the Command's own value on
                                purpose: that is which row is *highlighted*, and
                                it moves to the first match with every letter
                                typed. Tying the choice to it would have somebody
                                pick a trigger by typing three letters and
                                looking away. The tick says what is chosen, and
                                only clicking or Enter changes it.
                            */}
                            <Command className="rounded-md border">
                                <CommandInput
                                    id="new-workflow-trigger"
                                    placeholder={t(
                                        'settings.workflows.trigger_search',
                                    )}
                                />

                                <CommandList className="max-h-56">
                                    <CommandEmpty>
                                        {t(
                                            'settings.workflows.trigger_no_match',
                                        )}
                                    </CommandEmpty>

                                    {triggers.map((trigger) => (
                                        <CommandItem
                                            key={trigger.key}
                                            value={trigger.key}
                                            /*
                                             * What the filter reads. The value
                                             * is the key the server wants, and
                                             * a key like "contract.signed" is
                                             * not what anybody types — the
                                             * words on the screen are.
                                             */
                                            keywords={[
                                                trigger.label,
                                                trigger.description,
                                            ]}
                                            onSelect={setTriggerType}
                                            className="items-start gap-2"
                                        >
                                            <Check
                                                className={cn(
                                                    'mt-0.5 size-4 shrink-0',
                                                    trigger.key !==
                                                        triggerType &&
                                                        'opacity-0',
                                                )}
                                            />

                                            <div className="min-w-0">
                                                <p>{trigger.label}</p>

                                                {/*
                                                    The trigger's own sentence,
                                                    which a list of names cannot
                                                    say. Picking between "Bij een
                                                    trefwoord" and "Op een vast
                                                    moment" is a guess until you
                                                    read what each of them
                                                    actually waits for.
                                                */}
                                                <p className="text-xs text-muted-foreground">
                                                    {trigger.description}
                                                </p>
                                            </div>
                                        </CommandItem>
                                    ))}
                                </CommandList>
                            </Command>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCreating(false)}
                        >
                            {t('settings.actions.cancel')}
                        </Button>

                        <Button
                            disabled={name.trim() === '' || triggerType === ''}
                            onClick={() =>
                                router.post(store.url(), {
                                    name,
                                    trigger_type: triggerType,
                                })
                            }
                        >
                            <Plus className="size-4" />
                            {t('settings.workflows.create')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
