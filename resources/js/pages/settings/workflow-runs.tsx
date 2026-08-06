import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';

import { SettingsSection } from '@/components/settings-section';
import {
    ACTION_GLYPHS,
    FALLBACK_GLYPH,
    FORK_GLYPH,
    WorkflowNode,
} from '@/components/workflow-node';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as workflowsIndex } from '@/routes/workflows';

interface StepRun {
    position: number;
    actionType: string;
    action: string;
    /** Which lane this step stood in, when it stood in one. */
    branch: 'then' | 'else' | null;
    branchLabel: string | null;
    /** For a fork: the lane it sent the run down. */
    lane: string | null;
    status: 'succeeded' | 'skipped' | 'failed';
    statusLabel: string;
    failureReason: string | null;
    result: Record<string, unknown> | null;
}

interface Run {
    id: number;
    status: 'running' | 'waiting' | 'succeeded' | 'stopped' | 'failed';
    statusLabel: string;
    startedAt: string | null;
    finishedAt: string | null;
    resumeAt: string | null;
    failureReason: string | null;
    context: Record<string, unknown>;
    steps: StepRun[];
}

interface WorkflowRunsProps {
    workflow: { id: number; name: string; enabled: boolean };
    runs: { data: Run[]; links: { url: string | null; label: string }[] };
}

/**
 * Colour carries the same thing the label does, never anything extra.
 *
 * A skipped step is not a warning and not a success — it did exactly what its
 * condition said — so it gets the muted treatment rather than an amber that
 * would read as "something went slightly wrong". A run that a condition cut
 * short is the same story one level up, and gets the same muted grey.
 */
const TONE: Record<string, string> = {
    succeeded: 'text-emerald-600 dark:text-emerald-400',
    skipped: 'text-muted-foreground',
    stopped: 'text-muted-foreground',
    failed: 'text-destructive',
    running: 'text-blue-600 dark:text-blue-400',
    waiting: 'text-amber-600 dark:text-amber-400',
};

/** The same three answers, as the block's own icon reads them. */
const BLOCK_TONE: Record<string, string> = {
    succeeded: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    skipped: 'bg-muted text-muted-foreground',
    failed: 'bg-destructive/10 text-destructive',
};

function when(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString();
}

function RunRow({ run }: { run: Run }) {
    const { t } = useTranslate();
    const [open, setOpen] = useState(false);

    return (
        <div className="rounded-lg border">
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                className="flex w-full items-center gap-3 p-3 text-left"
            >
                <span className={cn('text-xs font-medium', TONE[run.status])}>
                    {run.statusLabel}
                </span>

                <span className="flex-1 text-xs text-muted-foreground">
                    {when(run.startedAt)}
                    {run.status === 'waiting' && run.resumeAt !== null && (
                        <>
                            {' · '}
                            {t('settings.workflow_runs.resumes_at', {
                                moment: when(run.resumeAt),
                            })}
                        </>
                    )}
                </span>

                {run.failureReason && (
                    <span className="max-w-md truncate text-xs text-destructive">
                        {run.failureReason}
                    </span>
                )}
            </button>

            {open && (
                <div className="space-y-3 border-t p-3">
                    {/*
                     * The path the run actually took, drawn the way the builder
                     * draws the workflow. Somebody who has read one screen can
                     * read this one: same blocks, same lanes, with what happened
                     * coloured in. What is missing from the picture is the lane
                     * that was not taken — deliberately, because those steps
                     * were never at the door.
                     */}
                    <div className="max-w-md">
                        {run.steps.map((step, index) => {
                            const fork = step.actionType === 'branch';
                            const before = run.steps[index - 1];

                            return (
                                <div
                                    key={index}
                                    className={cn(
                                        step.branch !== null &&
                                            'ml-4 border-l-2 pl-3',
                                    )}
                                >
                                    {/*
                                        The lane's name, said once where it
                                        starts rather than on every block in it.
                                    */}
                                    {step.branch !== null &&
                                        step.branch !== before?.branch && (
                                            <span className="block pt-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                                {step.branchLabel}
                                            </span>
                                        )}

                                    {index > 0 && (
                                        <div className="mx-auto h-3 w-px bg-border" />
                                    )}

                                    <WorkflowNode
                                        glyph={
                                            fork
                                                ? FORK_GLYPH
                                                : (ACTION_GLYPHS[
                                                      step.actionType
                                                  ] ?? FALLBACK_GLYPH)
                                        }
                                        kind={fork ? 'fork' : 'action'}
                                        number={step.position}
                                        label={step.action}
                                        summary={
                                            step.failureReason ??
                                            // A fork's line is worth reading
                                            // only for which way it went.
                                            (fork ? step.lane : null) ??
                                            step.statusLabel
                                        }
                                        tone={BLOCK_TONE[step.status]}
                                        muted={step.status === 'skipped'}
                                        trailing={
                                            <span
                                                className={cn(
                                                    'shrink-0 text-xs',
                                                    TONE[step.status],
                                                )}
                                            >
                                                {step.statusLabel}
                                            </span>
                                        }
                                    />
                                </div>
                            );
                        })}

                        {run.steps.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                {t('settings.workflow_runs.no_steps_ran')}
                            </p>
                        )}
                    </div>

                    {/*
                     * What the variables were at that moment, which answers most
                     * questions about why something odd ended up in a message.
                     * Raw JSON on purpose: a prettier rendering would hide the
                     * shape, and the shape is what somebody is checking.
                     */}
                    <details>
                        <summary className="cursor-pointer text-xs text-muted-foreground">
                            {t('settings.workflow_runs.context')}
                        </summary>
                        <pre className="mt-2 max-h-64 overflow-auto rounded bg-muted p-2 text-xs">
                            {JSON.stringify(run.context, null, 2)}
                        </pre>
                    </details>
                </div>
            )}
        </div>
    );
}

export default function WorkflowRuns({ workflow, runs }: WorkflowRunsProps) {
    const { t } = useTranslate();

    return (
        <>
            <Head title={workflow.name} />

            {/*
                Above the section rather than inside it: the way back belongs to
                the screen, not to the thing the section is about.
            */}
            <Link
                href={workflowsIndex.url()}
                className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" />
                {t('settings.workflow_runs.back')}
            </Link>

            <SettingsSection
                title={workflow.name}
                description={t('settings.workflow_runs.description')}
            >
                <div className="space-y-2">
                    {runs.data.map((run) => (
                        <RunRow key={run.id} run={run} />
                    ))}

                    {runs.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {workflow.enabled
                                ? t('settings.workflow_runs.none_yet')
                                : t('settings.workflow_runs.none_because_off')}
                        </p>
                    )}
                </div>

                {/* Kept for a fortnight — see PruneWorkflowRuns for why a run is
                    a debugging aid with a shelf life rather than an archive. */}
                <p className="text-xs text-muted-foreground">
                    {t('settings.workflow_runs.kept_for')}
                </p>
            </SettingsSection>
        </>
    );
}
