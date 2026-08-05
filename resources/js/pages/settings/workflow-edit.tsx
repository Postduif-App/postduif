import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    History,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';

import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    ACTION_GLYPHS,
    FALLBACK_GLYPH,
    FORK_GLYPH,
    TRIGGER_GLYPH,
    TRIGGER_GLYPHS,
    WorkflowNode,
} from '@/components/workflow-node';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import {
    destroy,
    index as workflowsIndex,
    runs as runsRoute,
    toggle,
    update,
} from '@/routes/workflows';

/**
 * One thing a trigger or an action needs to be told, exactly as the register
 * describes it.
 *
 * Nothing on this screen knows what fields a given action has. That comes down
 * from the server, and it is why adding an action to the register is enough to
 * make it usable here — a form with its own list would drift from what the
 * runner reads, and the first anybody would hear of it is a failed run.
 */
interface Field {
    key: string;
    type:
        | 'text'
        | 'long-text'
        | 'channel'
        | 'member'
        | 'emoji'
        | 'number'
        | 'words'
        | 'choice';
    label: string;
    hint: string | null;
    required: boolean;
    acceptsVariables: boolean;
    options: Record<string, string>;
}

interface Described {
    key: string;
    label: string;
    description: string;
    fields: Field[];
    /** Path => what it holds, for the variable picker. */
    provides: Record<string, string>;
}

/**
 * What may sit in a step's settings.
 *
 * Narrower than `unknown` on purpose: this whole object goes to the server as a
 * request body, and Inertia will only carry things it can put in form data. A
 * looser type here compiles and then fails at the one moment nobody is testing.
 */
type Setting = string | number | boolean | string[] | null;

interface Rule {
    path: string;
    operator: string;
    value: string;
}

interface Condition {
    match: string;
    otherwise: string;
    rules: Rule[];
}

/**
 * A condition as it came off the row, in whichever shape it was saved.
 *
 * The first version of this screen wrote the three keys of a single rule flat
 * on the condition itself. Those rows are still out there, so both shapes
 * arrive here — see readCondition, and EvaluateCondition on the server, which
 * makes the same allowance for the same reason.
 */
type SavedCondition = (Partial<Condition> & Partial<Rule>) | null;

/** The two lanes of a fork, by the names the server stores them under. */
type Lane = 'then' | 'else';

const LANES: Lane[] = ['then', 'else'];

/**
 * One block, of either kind.
 *
 * A fork carries no configuration and no action: what it has is a condition and
 * two lanes. Both kinds are the same type rather than a union, because every
 * helper here walks a mixed list of them and a union would mean narrowing at
 * every step of that walk to say something that the `kind` already says.
 */
interface Step {
    kind: 'action' | 'branch';
    actionType: string;
    config: Record<string, Setting>;
    condition: Condition | null;
    branches: Record<Lane, Step[]> | null;
}

interface SavedStep {
    kind?: 'action' | 'branch';
    actionType: string;
    config: Record<string, Setting>;
    condition: SavedCondition;
    branches: Record<Lane, SavedStep[]> | null;
}

interface Workflow {
    id: number;
    name: string;
    description: string | null;
    triggerType: string;
    triggerConfig: Record<string, Setting>;
    enabled: boolean;
    owner: string | null;
    steps: SavedStep[];
    /** Every step at every level, which is not what steps.length counts. */
    stepCount: number;
    webhookUrl: string | null;
    webhookPayload: Record<string, Setting> | null;
}

/**
 * The words a condition is written in, as the enums spell them.
 *
 * Sent down rather than listed here, so that an operator taken out of the
 * runner stops being offered on the same deploy.
 */
interface Grammar {
    operators: Record<string, string>;
    matches: Record<string, string>;
    outcomes: Record<string, string>;
    branches: Record<string, string>;
}

/**
 * The paths the last run found under a variable whose shape nobody could
 * describe, keyed by the variable it belongs to.
 *
 * The shape of somebody else's API is not something the register can know, so
 * the last answer is what stands in for it. It is a memory of one run and not a
 * promise about the next — which is why these are offered beside the ones the
 * register vouches for rather than instead of them.
 */
type Samples = Record<string, string[]>;

interface WorkflowEditProps {
    workflow: Workflow;
    samples: Samples;
    catalogue: { triggers: Described[]; actions: Described[] };
    grammar: Grammar;
    channels: { id: number; name: string }[];
    members: { id: number; name: string }[];
}

/** The two that compare against nothing, so their value box is hidden. */
const VALUELESS = ['is-empty', 'is-not-empty'];

/** The server refuses a sixth; saying so here beats a red flash on save. */
const MAX_RULES = 5;

/**
 * Where a block sits, as the way down to it.
 *
 * `[2]` is the third block of the workflow; `[2, 'then', 0]` is the first block
 * in the left-hand lane of the fork that sits there. An address rather than an
 * index because a workflow is a shape now, and every edit — insert, move,
 * remove, select — has to be able to name a block inside a lane.
 */
type Path = (number | Lane)[];

/** Which block on the canvas the panel beside it is showing. */
type Selection = { kind: 'trigger' } | { kind: 'step'; path: Path };

const samePath = (one: Path, other: Path): boolean =>
    one.length === other.length && one.every((part, at) => part === other[at]);

/** The list a path's last step lives in, and where in it. */
function parentOf(steps: Step[], path: Path): { list: Step[]; at: number } {
    let list = steps;

    for (let part = 0; part < path.length - 1; part += 2) {
        const lane = path[part + 1] as Lane;

        list = list[path[part] as number].branches?.[lane] ?? [];
    }

    return { list, at: path[path.length - 1] as number };
}

function stepAt(steps: Step[], path: Path): Step | undefined {
    const { list, at } = parentOf(steps, path);

    return list[at];
}

/**
 * A new tree with one list rewritten.
 *
 * Everything that edits the shape goes through here, so that no other code has
 * to know how to rebuild the levels above the one it changed. React needs a new
 * object at every level it should notice, and doing that by hand in five places
 * is five chances to change a lane without the canvas redrawing.
 */
function rewrite(
    steps: Step[],
    path: Path,
    change: (list: Step[], at: number) => Step[],
): Step[] {
    if (path.length <= 1) {
        return change(steps, path[0] as number);
    }

    const at = path[0] as number;
    const lane = path[1] as Lane;

    return steps.map((step, index) =>
        index !== at
            ? step
            : {
                  ...step,
                  branches: {
                      ...(step.branches ?? { then: [], else: [] }),
                      [lane]: rewrite(
                          step.branches?.[lane] ?? [],
                          path.slice(2),
                          change,
                      ),
                  } as Record<Lane, Step[]>,
              },
    );
}

/**
 * Every block in the order the server numbers them, with its address.
 *
 * A fork first, then its then-lane, then its else-lane, then whatever follows
 * it — the same walk writeSteps() does when it hands out positions. That
 * agreement is what makes {{ steps.3.channel.id }} on this screen point at the
 * step the runner will file under 3.
 */
function inReadingOrder(
    steps: Step[],
    prefix: Path = [],
): { step: Step; path: Path; position: number }[] {
    const found: { step: Step; path: Path; position: number }[] = [];

    const walk = (list: Step[], at: Path) => {
        list.forEach((step, index) => {
            const path = [...at, index];

            found.push({ step, path, position: found.length });

            for (const lane of LANES) {
                walk(step.branches?.[lane] ?? [], [...path, lane]);
            }
        });
    };

    walk(steps, prefix);

    return found;
}

/**
 * The blocks whose results a step at this address may read.
 *
 * Not simply "everything numbered lower". A step in one lane of a fork must not
 * offer what a step in the other lane produced — only one of the two ever runs,
 * so half of those variables would be a promise that is broken every other run.
 * What is left is the honest set: the blocks before it in its own lane, and
 * before each fork it sits inside.
 */
function inScopeFor(
    steps: Step[],
    path: Path,
    numbering: { step: Step; path: Path; position: number }[],
): { step: Step; position: number }[] {
    const seen: { step: Step; position: number }[] = [];

    let list = steps;

    for (let part = 0; part < path.length; part += 2) {
        const at = path[part] as number;

        for (let index = 0; index < at; index++) {
            const step = list[index];
            const numbered = numbering.find((one) => one.step === step);

            if (step.kind === 'action' && numbered) {
                seen.push({ step, position: numbered.position });
            }
        }

        const lane = path[part + 1] as Lane | undefined;

        if (lane === undefined) {
            break;
        }

        list = list[at]?.branches?.[lane] ?? [];
    }

    return seen;
}

/**
 * A condition in the one shape this screen works with.
 *
 * A condition with no rules left in it comes back as null rather than as an
 * empty box: that is what the server makes of it too, and a badge on the canvas
 * saying "alleen als" with nothing behind it would be a promise the runner does
 * not keep.
 */
/** A step and everything under it, in the one shape this screen works with. */
function readStep(saved: SavedStep): Step {
    const kind = saved.kind ?? 'action';

    return {
        kind,
        actionType: saved.actionType,
        config: saved.config,
        condition: readCondition(saved.condition),
        branches:
            kind === 'branch'
                ? {
                      then: (saved.branches?.then ?? []).map(readStep),
                      else: (saved.branches?.else ?? []).map(readStep),
                  }
                : null,
    };
}

function readCondition(saved: SavedCondition): Condition | null {
    if (saved === null || saved === undefined) {
        return null;
    }

    if (Array.isArray(saved.rules)) {
        return saved.rules.length === 0
            ? null
            : {
                  match: saved.match ?? 'all',
                  otherwise: saved.otherwise ?? 'skip',
                  rules: saved.rules.map((rule) => ({
                      path: rule.path ?? '',
                      operator: rule.operator ?? 'equals',
                      value: rule.value ?? '',
                  })),
              };
    }

    // The old flat shape, read as the single rule it always was.
    return typeof saved.path === 'string'
        ? {
              match: 'all',
              otherwise: 'skip',
              rules: [
                  {
                      path: saved.path,
                      operator: saved.operator ?? 'equals',
                      value: saved.value ?? '',
                  },
              ],
          }
        : null;
}

/**
 * Every path a block at this address may read, with what it holds.
 *
 * The trigger's own, plus whatever each block that is certain to have run
 * before it said it would leave behind. Later blocks are deliberately absent,
 * and so is the far lane of any fork this one sits in — see inScopeFor. A
 * variable that will not exist when the step runs is worse than no variable.
 */
function variablesFor(
    trigger: Described | undefined,
    inScope: { step: Step; position: number }[],
    actions: Described[],
    payload: Record<string, Setting> | null,
    samples: Samples,
): { path: string; what: string }[] {
    const found: { path: string; what: string }[] = [];

    for (const [path, what] of Object.entries(trigger?.provides ?? {})) {
        found.push({ path: `trigger.${path}`, what });
    }

    /*
     * A webhook's shape is the sender's, not ours, so the register can only
     * offer the word "payload". The last body that actually arrived is the one
     * honest source for the rest, which is why it is kept — see the model.
     */
    if (payload !== null) {
        for (const path of flatten(payload)) {
            found.push({ path: `trigger.payload.${path}`, what: path });
        }
    }

    for (const { step, position } of inScope) {
        const action = actions.find((one) => one.key === step.actionType);

        for (const [path, what] of Object.entries(action?.provides ?? {})) {
            const at = `steps.${position}.${path}`;

            found.push({ path: at, what });

            /*
             * An action that can only promise "some JSON" — the HTTP step —
             * gets the paths the last run actually found underneath it. The
             * word on its own leaves somebody guessing at
             * {{ steps.2.json.order.id }} until they have run it once and read
             * the run screen; this is that reading, done for them.
             */
            for (const inside of samples[at] ?? []) {
                found.push({ path: `${at}.${inside}`, what: inside });
            }
        }
    }

    return found;
}

/** Every leaf path in a remembered payload, dotted. */
function flatten(value: unknown, prefix = ''): string[] {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return prefix === '' ? [] : [prefix];
    }

    return Object.entries(value as Record<string, unknown>).flatMap(
        ([key, nested]) =>
            flatten(nested, prefix === '' ? key : `${prefix}.${key}`),
    );
}

/**
 * The one line a block shows under its name.
 *
 * The first field that has been filled in, because that is nearly always the
 * one that says what the step is about — a channel, a person, the opening words
 * of a message. Whatever it is, it beats reading "Bericht plaatsen" four times
 * down a column and having to open each to tell them apart.
 */
function summarise(
    described: Described | undefined,
    config: Record<string, Setting>,
    channels: WorkflowEditProps['channels'],
    members: WorkflowEditProps['members'],
): string | null {
    for (const field of described?.fields ?? []) {
        const value = config[field.key];

        if (
            value === undefined ||
            value === null ||
            value === '' ||
            (Array.isArray(value) && value.length === 0)
        ) {
            continue;
        }

        if (field.type === 'channel') {
            const channel = channels.find(
                (one) => String(one.id) === String(value),
            );

            return channel ? `#${channel.name}` : null;
        }

        if (field.type === 'member') {
            const member = members.find(
                (one) => String(one.id) === String(value),
            );

            return member?.name ?? null;
        }

        return Array.isArray(value) ? value.join(', ') : String(value);
    }

    return null;
}

function FieldInput({
    field,
    value,
    onChange,
    channels,
    members,
    variables,
}: {
    field: Field;
    value: Setting;
    onChange: (next: Setting) => void;
    channels: WorkflowEditProps['channels'];
    members: WorkflowEditProps['members'];
    variables: { path: string; what: string }[];
}) {
    const { t } = useTranslate();

    const insert = (path: string) =>
        onChange(`${String(value ?? '')}{{ ${path} }}`);

    const picker = field.acceptsVariables && variables.length > 0 && (
        <select
            value=""
            onChange={(event) => {
                if (event.target.value !== '') {
                    insert(event.target.value);
                }
            }}
            className="mt-1 w-full rounded-md border bg-background px-2 py-1 text-xs text-muted-foreground"
        >
            {/*
             * Nobody should have to type {{ trigger.message.text }} from
             * memory — they would type it wrong, and a mistyped path is a gap
             * in a message rather than an error anybody sees.
             */}
            <option value="">{t('settings.workflows.insert_variable')}</option>
            {variables.map((variable) => (
                <option key={variable.path} value={variable.path}>
                    {variable.what} — {`{{ ${variable.path} }}`}
                </option>
            ))}
        </select>
    );

    const common = { id: field.key, required: field.required };

    return (
        <div className="grid gap-1">
            <Label htmlFor={field.key} className="text-xs">
                {field.label}
                {!field.required && (
                    <span className="ml-1 text-muted-foreground">
                        {t('settings.workflows.optional')}
                    </span>
                )}
            </Label>

            {field.type === 'long-text' ? (
                <textarea
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    rows={3}
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                />
            ) : field.type === 'channel' ? (
                <select
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                >
                    <option value="">{t('settings.workflows.choose')}</option>
                    {channels.map((channel) => (
                        <option key={channel.id} value={channel.id}>
                            #{channel.name}
                        </option>
                    ))}
                </select>
            ) : field.type === 'member' ? (
                <select
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                >
                    <option value="">{t('settings.workflows.choose')}</option>
                    {members.map((member) => (
                        <option key={member.id} value={member.id}>
                            {member.name}
                        </option>
                    ))}
                </select>
            ) : field.type === 'choice' ? (
                <select
                    {...common}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                >
                    <option value="">{t('settings.workflows.choose')}</option>
                    {Object.entries(field.options).map(([option, label]) => (
                        <option key={option} value={option}>
                            {label}
                        </option>
                    ))}
                </select>
            ) : field.type === 'words' ? (
                <Input
                    {...common}
                    value={
                        Array.isArray(value)
                            ? (value as string[]).join(', ')
                            : ''
                    }
                    onChange={(event) =>
                        onChange(
                            event.target.value
                                .split(',')
                                .map((word) => word.trim())
                                .filter((word) => word !== ''),
                        )
                    }
                    placeholder={t('settings.workflows.words_placeholder')}
                />
            ) : (
                <Input
                    {...common}
                    type={field.type === 'number' ? 'number' : 'text'}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}

            {field.hint && (
                <p className="text-xs text-muted-foreground">{field.hint}</p>
            )}

            {picker}
        </div>
    );
}

/**
 * The line between two blocks, and everything that can be said on it.
 *
 * The condition sits here rather than inside the block below it because that is
 * what a condition is: a gate between one step and the next, not a setting of
 * the step. Somebody scanning the column sees where the workflow can fork off
 * without opening anything.
 */
function Connector({
    condition,
    grammar,
    onInsert,
    onOpen,
}: {
    condition: Condition | null;
    grammar: Grammar;
    onInsert: () => void;
    onOpen?: () => void;
}) {
    const { t, tChoice } = useTranslate();

    const stops = condition?.otherwise === 'stop';

    return (
        <div className="group/line relative flex flex-col items-center py-1">
            <div className="h-3 w-px bg-border" />

            {condition !== null && (
                <button
                    type="button"
                    onClick={onOpen}
                    className={cn(
                        'my-1 flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] transition-colors',
                        stops
                            ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                            : 'bg-background text-muted-foreground hover:text-foreground',
                    )}
                >
                    <span className="font-medium">
                        {t('settings.workflows.only_when')}
                    </span>
                    <span>
                        {tChoice(
                            'settings.workflows.rule_count',
                            condition.rules.length,
                        )}
                    </span>
                    {/*
                        Which way the condition cuts, said on the badge itself.
                        The two do very different things to a run and the
                        difference is otherwise a click away.
                    */}
                    {stops && (
                        <span className="opacity-80">
                            · {t('settings.workflows.stops_otherwise')}
                        </span>
                    )}
                    <span className="sr-only">
                        {grammar.matches[condition.match] ?? condition.match}
                    </span>
                </button>
            )}

            <div className="h-3 w-px bg-border" />

            <button
                type="button"
                onClick={onInsert}
                aria-label={t('settings.workflows.insert_step')}
                title={t('settings.workflows.insert_step')}
                className="absolute top-1/2 right-0 flex size-5 -translate-y-1/2 items-center justify-center rounded-full border bg-background text-muted-foreground opacity-0 transition-opacity group-focus-within/line:opacity-100 group-hover/line:opacity-100 hover:text-foreground focus-visible:opacity-100"
            >
                <Plus className="size-3" />
            </button>
        </div>
    );
}

/**
 * One level of the canvas: a run of blocks, and the lanes hanging off any fork
 * among them.
 *
 * Recursive, and it is the same component all the way down — a lane is a run of
 * blocks like any other. What differs is only the indent and the word at the
 * top of it, which is the whole argument for drawing lanes stacked rather than
 * side by side: a lane that is a column like the rest stays readable however
 * narrow the panel gets.
 */
function Blocks({
    steps,
    at,
    catalogue,
    grammar,
    numbering,
    selected,
    channels,
    members,
    onSelect,
    onInsert,
    onMove,
    onRemove,
}: {
    steps: Step[];
    at: Path;
    catalogue: WorkflowEditProps['catalogue'];
    grammar: Grammar;
    numbering: { step: Step; path: Path; position: number }[];
    selected: Selection;
    channels: WorkflowEditProps['channels'];
    members: WorkflowEditProps['members'];
    onSelect: (path: Path) => void;
    onInsert: (path: Path, kind: Step['kind']) => void;
    onMove: (path: Path, to: number) => void;
    onRemove: (path: Path) => void;
}) {
    const { t } = useTranslate();

    return (
        <>
            {steps.map((step, index) => {
                const path = [...at, index];
                const action = catalogue.actions.find(
                    (one) => one.key === step.actionType,
                );
                const fork = step.kind === 'branch';

                return (
                    <div key={index}>
                        <Connector
                            /*
                             * A fork's condition belongs to the fork, not to the
                             * line above it: it is not a gate this block has to
                             * pass, it is the question the block asks. Putting
                             * it on the line would read as though the fork
                             * itself could be skipped.
                             */
                            condition={fork ? null : step.condition}
                            grammar={grammar}
                            onInsert={() => onInsert(path, 'action')}
                            onOpen={() => onSelect(path)}
                        />

                        <WorkflowNode
                            glyph={
                                fork
                                    ? FORK_GLYPH
                                    : (ACTION_GLYPHS[step.actionType] ??
                                      FALLBACK_GLYPH)
                            }
                            kind={fork ? 'fork' : 'action'}
                            number={
                                numbering.find((one) => one.step === step)
                                    ?.position
                            }
                            label={
                                fork
                                    ? t('settings.workflows.branch')
                                    : (action?.label ?? step.actionType)
                            }
                            summary={
                                fork
                                    ? null
                                    : (summarise(
                                          action,
                                          step.config,
                                          channels,
                                          members,
                                      ) ?? t('settings.workflows.unconfigured'))
                            }
                            selected={
                                selected.kind === 'step' &&
                                samePath(selected.path, path)
                            }
                            onSelect={() => onSelect(path)}
                            tools={
                                <>
                                    <button
                                        type="button"
                                        onClick={() => onMove(path, index - 1)}
                                        aria-label={t(
                                            'settings.workflows.move_up',
                                        )}
                                        className="rounded bg-background/80 p-0.5 text-muted-foreground hover:text-foreground"
                                    >
                                        <ArrowUp className="size-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => onMove(path, index + 1)}
                                        aria-label={t(
                                            'settings.workflows.move_down',
                                        )}
                                        className="rounded bg-background/80 p-0.5 text-muted-foreground hover:text-foreground"
                                    >
                                        <ArrowDown className="size-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => onRemove(path)}
                                        aria-label={t(
                                            'settings.workflows.remove_step',
                                        )}
                                        className="rounded bg-background/80 p-0.5 text-muted-foreground hover:text-destructive"
                                    >
                                        <X className="size-3.5" />
                                    </button>
                                </>
                            }
                        />

                        {fork &&
                            LANES.map((lane) => (
                                <div
                                    key={lane}
                                    className="mt-1 ml-4 border-l-2 pl-3"
                                >
                                    <span className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                        {grammar.branches[lane] ?? lane}
                                    </span>

                                    <Blocks
                                        steps={step.branches?.[lane] ?? []}
                                        at={[...path, lane]}
                                        catalogue={catalogue}
                                        grammar={grammar}
                                        numbering={numbering}
                                        selected={selected}
                                        channels={channels}
                                        members={members}
                                        onSelect={onSelect}
                                        onInsert={onInsert}
                                        onMove={onMove}
                                        onRemove={onRemove}
                                    />

                                    <button
                                        type="button"
                                        onClick={() =>
                                            onInsert(
                                                [
                                                    ...path,
                                                    lane,
                                                    step.branches?.[lane]
                                                        .length ?? 0,
                                                ],
                                                'action',
                                            )
                                        }
                                        className="mt-1 flex w-full items-center justify-center gap-1 rounded-md border border-dashed py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        <Plus className="size-3" />
                                        {t('settings.workflows.add_step')}
                                    </button>
                                </div>
                            ))}
                    </div>
                );
            })}
        </>
    );
}

function ConditionEditor({
    condition,
    variables,
    grammar,
    withOutcome = true,
    onChange,
}: {
    condition: Condition | null;
    variables: { path: string; what: string }[];
    grammar: Grammar;
    /**
     * A fork's condition has no "and otherwise". Its otherwise is the second
     * lane, drawn right there on the canvas — offering the dropdown as well
     * would be two answers to one question.
     */
    withOutcome?: boolean;
    onChange: (condition: Condition | null) => void;
}) {
    const { t } = useTranslate();

    const blankRule = (): Rule => ({
        path: variables[0]?.path ?? '',
        operator: 'equals',
        value: '',
    });

    if (condition === null) {
        return (
            <button
                type="button"
                onClick={() =>
                    onChange({
                        match: 'all',
                        otherwise: 'skip',
                        rules: [blankRule()],
                    })
                }
                className="text-xs text-muted-foreground underline"
            >
                {t('settings.workflows.add_condition')}
            </button>
        );
    }

    const change = (at: number, rule: Rule) =>
        onChange({
            ...condition,
            rules: condition.rules.map((one, index) =>
                index === at ? rule : one,
            ),
        });

    return (
        <div className="grid gap-2 rounded-md bg-muted/40 p-2">
            <div className="flex flex-wrap items-center gap-2">
                <Label className="text-xs">
                    {t('settings.workflows.only_when')}
                </Label>

                <select
                    value={condition.match}
                    onChange={(event) =>
                        onChange({ ...condition, match: event.target.value })
                    }
                    className="rounded-md border bg-background px-2 py-1 text-xs"
                >
                    {Object.entries(grammar.matches).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>

                {/*
                    A fork with no condition would always take the same lane,
                    which is a fork nobody wrote on purpose. There is a way to
                    be rid of it — remove the fork — and it is on the canvas.
                */}
                {withOutcome && (
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        aria-label={t('settings.workflows.remove_condition')}
                        className="ml-auto text-muted-foreground hover:text-destructive"
                    >
                        <X className="size-3.5" />
                    </button>
                )}
            </div>

            {condition.rules.map((rule, at) => (
                <div key={at} className="flex flex-wrap items-center gap-2">
                    <select
                        value={rule.path}
                        onChange={(event) =>
                            change(at, { ...rule, path: event.target.value })
                        }
                        className="rounded-md border bg-background px-2 py-1 text-xs"
                    >
                        {variables.map((variable) => (
                            <option key={variable.path} value={variable.path}>
                                {variable.what}
                            </option>
                        ))}
                    </select>

                    <select
                        value={rule.operator}
                        onChange={(event) =>
                            change(at, {
                                ...rule,
                                operator: event.target.value,
                            })
                        }
                        className="rounded-md border bg-background px-2 py-1 text-xs"
                    >
                        {Object.entries(grammar.operators).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>

                    {/* Hidden for the two that compare against nothing: a box
                        that is ignored is a box somebody fills in and then
                        wonders about. */}
                    {!VALUELESS.includes(rule.operator) && (
                        <Input
                            value={rule.value}
                            onChange={(event) =>
                                change(at, {
                                    ...rule,
                                    value: event.target.value,
                                })
                            }
                            className="h-7 w-40 text-xs"
                        />
                    )}

                    {/*
                        The last rule keeps no remove button. Taking it out is
                        taking the condition out, and that is what the cross
                        above is for — two ways to reach the same nothing is one
                        too many.
                    */}
                    {condition.rules.length > 1 && (
                        <button
                            type="button"
                            onClick={() =>
                                onChange({
                                    ...condition,
                                    rules: condition.rules.filter(
                                        (_, index) => index !== at,
                                    ),
                                })
                            }
                            aria-label={t('settings.workflows.remove_rule')}
                            className="text-muted-foreground hover:text-destructive"
                        >
                            <X className="size-3.5" />
                        </button>
                    )}
                </div>
            ))}

            <div className="flex flex-wrap items-center gap-2">
                {condition.rules.length < MAX_RULES && (
                    <button
                        type="button"
                        onClick={() =>
                            onChange({
                                ...condition,
                                rules: [...condition.rules, blankRule()],
                            })
                        }
                        className="text-xs text-muted-foreground underline"
                    >
                        {t('settings.workflows.add_rule')}
                    </button>
                )}

                {withOutcome && (
                    <span className="ml-auto flex items-center gap-2">
                        <Label className="text-xs">
                            {t('settings.workflows.otherwise')}
                        </Label>

                        <select
                            value={condition.otherwise}
                            onChange={(event) =>
                                onChange({
                                    ...condition,
                                    otherwise: event.target.value,
                                })
                            }
                            className="rounded-md border bg-background px-2 py-1 text-xs"
                        >
                            {Object.entries(grammar.outcomes).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </span>
                )}
            </div>
        </div>
    );
}

export default function WorkflowEdit({
    workflow,
    samples,
    catalogue,
    grammar,
    channels,
    members,
}: WorkflowEditProps) {
    const { t, tChoice } = useTranslate();
    const [name, setName] = useState(workflow.name);
    const [triggerType, setTriggerType] = useState(workflow.triggerType);
    const [triggerConfig, setTriggerConfig] = useState<Record<string, Setting>>(
        workflow.triggerConfig ?? {},
    );
    const [steps, setSteps] = useState<Step[]>(() =>
        workflow.steps.map(readStep),
    );
    const [selected, setSelected] = useState<Selection>({ kind: 'trigger' });

    const trigger = catalogue.triggers.find((one) => one.key === triggerType);

    const numbering = inReadingOrder(steps);

    /** A step as the server wants it, lanes and all. */
    const asPayload = (step: Step): Record<string, unknown> => ({
        kind: step.kind,
        action_type: step.actionType,
        config: step.config,
        condition: step.condition,
        branches:
            step.branches === null
                ? null
                : {
                      then: step.branches.then.map(asPayload),
                      else: step.branches.else.map(asPayload),
                  },
    });

    const save = () =>
        router.put(
            update.url(workflow.id),
            {
                name,
                description: workflow.description,
                trigger_type: triggerType,
                trigger_config: triggerConfig,
                /*
                 * Cast at the seam rather than loosening Setting. What goes
                 * over the wire is nested objects, which Inertia carries as
                 * JSON perfectly well — its FormDataConvertible type simply
                 * does not describe that. Narrowing the model to satisfy a type
                 * about form encoding would be the tail wagging the dog.
                 */
                steps: steps.map(asPayload) as unknown as Record<
                    string,
                    never
                >[],
            },
            { preserveScroll: true },
        );

    const move = (path: Path, to: number) => {
        setSteps((current) =>
            rewrite(current, path, (list, at) => {
                if (to < 0 || to >= list.length) {
                    return list;
                }

                const next = [...list];
                const [moved] = next.splice(at, 1);
                next.splice(to, 0, moved);

                return next;
            }),
        );

        // The panel follows the block rather than the place. Somebody who moves
        // a step up is still working on that step.
        setSelected({ kind: 'step', path: [...path.slice(0, -1), to] });
    };

    const insertAt = (path: Path, kind: Step['kind']) => {
        const fresh: Step =
            kind === 'branch'
                ? {
                      kind,
                      actionType: 'branch',
                      config: {},
                      /*
                       * A fork arrives with its question already half-written.
                       * One with no condition takes the same lane every time,
                       * which is not something anybody adds a fork in order to
                       * get.
                       */
                      condition: { match: 'all', otherwise: 'skip', rules: [] },
                      branches: { then: [], else: [] },
                  }
                : {
                      kind,
                      actionType: catalogue.actions[0]?.key ?? '',
                      config: {},
                      condition: null,
                      branches: null,
                  };

        setSteps((current) =>
            rewrite(current, path, (list, at) => [
                ...list.slice(0, at),
                fresh,
                ...list.slice(at),
            ]),
        );

        setSelected({ kind: 'step', path });
    };

    const removeAt = (path: Path) => {
        setSteps((current) =>
            rewrite(current, path, (list, at) =>
                list.filter((_, index) => index !== at),
            ),
        );

        /*
         * Back to the trigger rather than to the neighbour. Whichever step
         * slides into this place is not the one that was being edited, and a
         * panel that silently starts showing a different step is how somebody
         * types a channel name into the wrong one.
         */
        setSelected({ kind: 'trigger' });
    };

    const changeStep = (path: Path, change: Partial<Step>) =>
        setSteps((current) =>
            rewrite(current, path, (list, at) =>
                list.map((one, index) =>
                    index === at ? { ...one, ...change } : one,
                ),
            ),
        );

    return (
        <>
            <Head title={workflow.name} />

            <div className="space-y-6">
                <Link
                    href={workflowsIndex.url()}
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {t('settings.workflows.back')}
                </Link>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex-1">
                        <Heading
                            title={workflow.name}
                            description={`${trigger?.label ?? workflow.triggerType} · ${tChoice(
                                'settings.workflows.step_count',
                                workflow.stepCount,
                            )}`}
                        />
                    </div>

                    {/*
                        Switching on is its own request rather than part of
                        saving, because it is the one decision that changes
                        whether the thing acts on the workspace at all — see
                        Workflow::enable(), which is a method for the same
                        reason.
                    */}
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
                        onClick={() => router.delete(destroy.url(workflow.id))}
                        aria-label={t('settings.workflows.delete')}
                        className="text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                    </button>
                </div>

                <div className="space-y-4">
                    <div className="grid gap-1">
                        <Label htmlFor={`name-${workflow.id}`}>
                            {t('settings.workflows.name')}
                        </Label>
                        <Input
                            id={`name-${workflow.id}`}
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('settings.workflows.name_hint')}
                        </p>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[minmax(0,30rem)_minmax(0,1fr)] lg:items-start">
                        {/* The canvas. */}
                        <div className="rounded-lg bg-muted/30 p-3">
                            <WorkflowNode
                                glyph={
                                    TRIGGER_GLYPHS[triggerType] ?? TRIGGER_GLYPH
                                }
                                kind="trigger"
                                label={trigger?.label ?? triggerType}
                                summary={
                                    summarise(
                                        trigger,
                                        triggerConfig,
                                        channels,
                                        members,
                                    ) ?? t('settings.workflows.unconfigured')
                                }
                                selected={selected.kind === 'trigger'}
                                onSelect={() =>
                                    setSelected({ kind: 'trigger' })
                                }
                            />

                            <Blocks
                                steps={steps}
                                at={[]}
                                catalogue={catalogue}
                                grammar={grammar}
                                numbering={numbering}
                                selected={selected}
                                channels={channels}
                                members={members}
                                onSelect={(path) =>
                                    setSelected({ kind: 'step', path })
                                }
                                onInsert={insertAt}
                                onMove={move}
                                onRemove={removeAt}
                            />

                            <Connector
                                condition={null}
                                grammar={grammar}
                                onInsert={() =>
                                    insertAt([steps.length], 'action')
                                }
                            />

                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="flex-1"
                                    onClick={() =>
                                        insertAt([steps.length], 'action')
                                    }
                                >
                                    <Plus className="size-4" />
                                    {t('settings.workflows.add_step')}
                                </Button>

                                {/*
                                    Only at the top. A fork inside a lane is
                                    something the runner and the database would
                                    both take, and the server refuses it anyway —
                                    what cannot take it is the reading.
                                */}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        insertAt([steps.length], 'branch')
                                    }
                                >
                                    <FORK_GLYPH className="size-4" />
                                    {t('settings.workflows.add_branch')}
                                </Button>
                            </div>

                            {steps.length === 0 && (
                                <p className="pt-2 text-center text-xs text-muted-foreground">
                                    {t('settings.workflows.no_steps_yet')}
                                </p>
                            )}
                        </div>

                        {/* The panel: whatever is selected on the canvas. */}
                        <div className="space-y-4 rounded-lg border p-3">
                            {selected.kind === 'trigger' ? (
                                <>
                                    <div className="grid gap-1">
                                        <Label
                                            htmlFor={`trigger-${workflow.id}`}
                                        >
                                            {t('settings.workflows.trigger')}
                                        </Label>
                                        <select
                                            id={`trigger-${workflow.id}`}
                                            value={triggerType}
                                            onChange={(event) => {
                                                setTriggerType(
                                                    event.target.value,
                                                );
                                                // The old settings belong to the
                                                // old trigger, and keeping them
                                                // would leave a channel id in a
                                                // webhook's bag for nobody to
                                                // see or remove.
                                                setTriggerConfig({});
                                            }}
                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                        >
                                            {catalogue.triggers.map((one) => (
                                                <option
                                                    key={one.key}
                                                    value={one.key}
                                                >
                                                    {one.label}
                                                </option>
                                            ))}
                                        </select>
                                        {trigger && (
                                            <p className="text-xs text-muted-foreground">
                                                {trigger.description}
                                            </p>
                                        )}
                                    </div>

                                    {trigger?.fields.map((field) => (
                                        <FieldInput
                                            key={field.key}
                                            field={field}
                                            value={triggerConfig[field.key]}
                                            onChange={(next) =>
                                                setTriggerConfig((current) => ({
                                                    ...current,
                                                    [field.key]: next,
                                                }))
                                            }
                                            channels={channels}
                                            members={members}
                                            variables={[]}
                                        />
                                    ))}

                                    {/*
                                        Keyed off the trigger that is selected
                                        right now rather than off whether a URL
                                        exists. The token stays on the row once
                                        minted — so that switching back to a
                                        webhook does not break an integration
                                        over a change of mind — and showing it
                                        beside a keyword trigger would offer a
                                        URL that the endpoint refuses anyway.
                                    */}
                                    {triggerType === 'webhook' && (
                                        <div className="grid gap-1">
                                            <Label>
                                                {t(
                                                    'settings.workflows.webhook_url',
                                                )}
                                            </Label>

                                            {workflow.webhookUrl === null ? (
                                                // A workflow that has only just
                                                // become a webhook one has no
                                                // URL until it is saved, and
                                                // saying so beats an empty box
                                                // nobody can copy.
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'settings.workflows.webhook_url_pending',
                                                    )}
                                                </p>
                                            ) : (
                                                <Input
                                                    readOnly
                                                    value={workflow.webhookUrl}
                                                    className="font-mono text-xs"
                                                    onFocus={(event) =>
                                                        event.target.select()
                                                    }
                                                />
                                            )}
                                        </div>
                                    )}
                                </>
                            ) : stepAt(steps, selected.path) === undefined ? (
                                <p className="text-xs text-muted-foreground">
                                    {t('settings.workflows.nothing_selected')}
                                </p>
                            ) : (
                                <StepPanel
                                    path={selected.path}
                                    step={stepAt(steps, selected.path)!}
                                    number={
                                        numbering.find((one) =>
                                            samePath(
                                                one.path,
                                                (
                                                    selected as {
                                                        path: Path;
                                                    }
                                                ).path,
                                            ),
                                        )?.position ?? 0
                                    }
                                    variables={variablesFor(
                                        trigger,
                                        inScopeFor(
                                            steps,
                                            selected.path,
                                            numbering,
                                        ),
                                        catalogue.actions,
                                        workflow.webhookPayload,
                                        samples,
                                    )}
                                    catalogue={catalogue}
                                    grammar={grammar}
                                    channels={channels}
                                    members={members}
                                    onChange={(change) =>
                                        changeStep(
                                            (selected as { path: Path }).path,
                                            change,
                                        )
                                    }
                                />
                            )}
                        </div>
                    </div>

                    <Button onClick={save}>
                        {t('settings.workflows.save')}
                    </Button>
                </div>
            </div>
        </>
    );
}

/**
 * Everything one step is configured with, beside the canvas.
 *
 * Its own component rather than a branch in the card, because it is the half of
 * this screen that changes every time the register does, and reading it next to
 * the canvas code makes both harder to follow than either alone.
 */
function StepPanel({
    path,
    step,
    number,
    variables,
    catalogue,
    grammar,
    channels,
    members,
    onChange,
}: {
    path: Path;
    step: Step;
    number: number;
    variables: { path: string; what: string }[];
    catalogue: WorkflowEditProps['catalogue'];
    grammar: Grammar;
    channels: WorkflowEditProps['channels'];
    members: WorkflowEditProps['members'];
    onChange: (change: Partial<Step>) => void;
}) {
    const { t } = useTranslate();

    const action = catalogue.actions.find((one) => one.key === step.actionType);

    const id = path.join('-');

    if (step.kind === 'branch') {
        return (
            <>
                <div className="grid gap-1">
                    <Label>
                        {t('settings.workflows.branch')} · {number}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        {t('settings.workflows.branch_hint')}
                    </p>
                </div>

                <ConditionEditor
                    condition={step.condition}
                    variables={variables}
                    grammar={grammar}
                    withOutcome={false}
                    onChange={(condition) => onChange({ condition })}
                />
            </>
        );
    }

    return (
        <>
            <div className="grid gap-1">
                <Label htmlFor={`action-${id}`}>
                    {t('settings.workflows.settings_for')} · {number}
                </Label>

                <select
                    id={`action-${id}`}
                    value={step.actionType}
                    onChange={(event) =>
                        onChange({
                            actionType: event.target.value,
                            // Same reasoning as the trigger: the old settings
                            // were the old action's.
                            config: {},
                        })
                    }
                    className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                >
                    {catalogue.actions.map((one) => (
                        <option key={one.key} value={one.key}>
                            {one.label}
                        </option>
                    ))}
                </select>

                {action && (
                    <p className="text-xs text-muted-foreground">
                        {action.description}
                    </p>
                )}
            </div>

            {action?.fields.map((field) => (
                <FieldInput
                    key={field.key}
                    field={field}
                    value={step.config[field.key]}
                    onChange={(next) =>
                        onChange({
                            config: { ...step.config, [field.key]: next },
                        })
                    }
                    channels={channels}
                    members={members}
                    variables={variables}
                />
            ))}

            <ConditionEditor
                condition={step.condition}
                variables={variables}
                grammar={grammar}
                onChange={(condition) => onChange({ condition })}
            />
        </>
    );
}
