import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, History, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    TRIGGER_GLYPH,
    TRIGGER_GLYPHS,
    WorkflowNode,
} from '@/components/workflow-node';
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
 * A workflow as this screen needs it: a name, what sets it off, how big it is.
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
 * One workflow in the list.
 *
 * The whole row is the way in, and everything beside it — on or off, the
 * history, the wastebasket — is something that can be done without opening it.
 * Those three are the reason this is not simply one big link.
 */
function WorkflowRow({
    workflow,
    triggers,
}: {
    workflow: Workflow;
    triggers: Described[];
}) {
    const { t, tChoice } = useTranslate();

    const trigger = triggers.find((one) => one.key === workflow.triggerType);

    return (
        <div className="flex items-center gap-3">
            <Link
                href={edit.url(workflow.id)}
                className="min-w-0 flex-1"
                aria-label={workflow.name}
            >
                <WorkflowNode
                    glyph={
                        TRIGGER_GLYPHS[workflow.triggerType] ?? TRIGGER_GLYPH
                    }
                    kind="trigger"
                    label={workflow.name}
                    summary={`${trigger?.label ?? workflow.triggerType} · ${tChoice(
                        'settings.workflows.step_count',
                        workflow.stepCount,
                    )}`}
                    /*
                     * A workflow that is off is not broken and not a warning —
                     * it simply is not doing anything, which is what the muted
                     * treatment says everywhere else on these screens.
                     */
                    muted={!workflow.enabled}
                    trailing={
                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                    }
                />
            </Link>

            <label className="flex items-center gap-2 text-xs">
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
    );
}

export default function Workflows({ workflows, triggers }: WorkflowsProps) {
    const { t } = useTranslate();
    const [name, setName] = useState('');
    const [triggerType, setTriggerType] = useState(triggers[0]?.key ?? '');

    return (
        <>
            <Head title={t('settings.workflows.title')} />

            <div className="space-y-6">
                <Heading
                    title={t('settings.workflows.title')}
                    description={t('settings.workflows.description')}
                />

                <div className="space-y-2">
                    {workflows.map((workflow) => (
                        <WorkflowRow
                            key={workflow.id}
                            workflow={workflow}
                            triggers={triggers}
                        />
                    ))}

                    {workflows.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('settings.workflows.none')}
                        </p>
                    )}
                </div>

                <div className="space-y-3 rounded-lg border p-4">
                    <Label htmlFor="new-workflow">
                        {t('settings.workflows.new')}
                    </Label>

                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            id="new-workflow"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder={t('settings.workflows.name')}
                            className={cn('max-w-xs')}
                        />

                        <select
                            value={triggerType}
                            onChange={(event) =>
                                setTriggerType(event.target.value)
                            }
                            className="rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            {triggers.map((trigger) => (
                                <option key={trigger.key} value={trigger.key}>
                                    {trigger.label}
                                </option>
                            ))}
                        </select>

                        <Button
                            disabled={name.trim() === ''}
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
                    </div>

                    {/*
                        A new workflow arrives switched off and opens straight
                        into the builder — saying so here beats leaving somebody
                        to wonder, once they are on that screen, why nothing has
                        happened yet.
                    */}
                    <p className="text-xs text-muted-foreground">
                        {t('settings.workflows.created_off')}
                    </p>
                </div>
            </div>
        </>
    );
}
