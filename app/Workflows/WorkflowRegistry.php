<?php

namespace App\Workflows;

use App\Models\Workspace;
use InvalidArgumentException;

/**
 * Every trigger and every action there is, in the order somebody should see
 * them.
 *
 * Listed rather than discovered from the directory, for the reason the workspace
 * features give: the order in a menu is a choice, and an action appearing there
 * the moment a file is created is the kind of surprise that makes a builder
 * untrustworthy.
 *
 * Where this departs from WorkspaceFeature is in being an object rather than a
 * const. The runner needs a seam — a test about what the runner does when a
 * step fails should be able to hang a failing step off the register rather than
 * find a real action that happens to break — and a const gives no such seam.
 * The cost is one binding in AppServiceProvider, which is where the real lists
 * live.
 */
class WorkflowRegistry
{
    /** @var array<string, class-string<WorkflowTrigger>> */
    private array $triggers = [];

    /** @var array<string, class-string<WorkflowAction>> */
    private array $actions = [];

    /**
     * @param  list<class-string<WorkflowTrigger>>  $triggers
     * @param  list<class-string<WorkflowAction>>  $actions
     */
    public function __construct(array $triggers = [], array $actions = [])
    {
        foreach ($triggers as $trigger) {
            $this->registerTrigger($trigger);
        }

        foreach ($actions as $action) {
            $this->registerAction($action);
        }
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     */
    public function registerTrigger(string $trigger): void
    {
        $this->guardAgainstDuplicate($trigger::key(), array_keys($this->triggers), 'trigger');

        $this->triggers[$trigger::key()] = $trigger;
    }

    /**
     * @param  class-string<WorkflowAction>  $action
     */
    public function registerAction(string $action): void
    {
        $this->guardAgainstDuplicate($action::key(), array_keys($this->actions), 'actie');

        $this->actions[$action::key()] = $action;
    }

    /** @return array<string, class-string<WorkflowTrigger>> */
    public function triggers(): array
    {
        return $this->triggers;
    }

    /** @return array<string, class-string<WorkflowAction>> */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * The trigger that answers to a stored key, or null when nothing does.
     *
     * Null rather than a throw, because the caller is usually reading a row
     * somebody saved earlier: a workflow whose trigger has since been taken out
     * of the register should be shown as broken, not bring the page down.
     *
     * @return class-string<WorkflowTrigger>|null
     */
    public function trigger(string $key): ?string
    {
        return $this->triggers[$key] ?? null;
    }

    /**
     * @return class-string<WorkflowAction>|null
     */
    public function action(string $key): ?string
    {
        return $this->actions[$key] ?? null;
    }

    /**
     * A ready-to-run instance of the action behind a key.
     *
     * Through the container, so an action may ask for SendMessage or
     * ToggleReaction in its constructor rather than reaching for the container
     * itself halfway through run().
     */
    public function resolveAction(string $key): ?WorkflowAction
    {
        $action = $this->action($key);

        return $action === null ? null : app($action);
    }

    /**
     * What the builder is handed: every trigger and action with its fields.
     *
     * A workspace narrows the triggers to the ones it could actually use. A
     * trigger that can never fire here is worse than one that is not offered:
     * somebody picks "contract getekend" in a workspace that asks for no
     * signatures, saves it, switches it on, and waits for a workflow that has
     * nothing to listen to. The listener asks this same question before it
     * starts anything — see StartMatchingWorkflows — so leaving the workspace
     * out only ever means offering a choice the runner will decline.
     *
     * Left out entirely, the catalogue is everything there is, which is what a
     * test about the register itself wants.
     *
     * $keep is the one exception, and the builder is why it exists. A workflow
     * written while contracts were on is still pointed at a contract trigger
     * after they are switched off; drop it from the list and the picker falls
     * back to whatever sits at the top, so opening that workflow and pressing
     * save would quietly point it somewhere else. Its own trigger stays in the
     * list — visibly the odd one out, since nothing else offers it — and
     * changing it away is then somebody's decision rather than a side effect.
     *
     * @param  string|null  $keep  A trigger key to offer whether or not the workspace could pick it today.
     * @return array{triggers: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function toArray(?Workspace $workspace = null, ?string $keep = null): array
    {
        $triggers = $workspace === null
            ? $this->triggers
            : array_filter(
                $this->triggers,
                fn (string $trigger, string $key): bool => $key === $keep || $trigger::availableFor($workspace),
                ARRAY_FILTER_USE_BOTH,
            );

        return [
            'triggers' => array_values(array_map(fn (string $trigger): array => [
                'key' => $trigger::key(),
                'label' => $trigger::label(),
                'description' => $trigger::description(),
                'fields' => array_map(fn (WorkflowField $field): array => $field->toArray(), $trigger::fields()),
                'provides' => $trigger::provides(),
            ], $triggers)),
            'actions' => array_values(array_map(fn (string $action): array => [
                'key' => $action::key(),
                'label' => $action::label(),
                'description' => $action::description(),
                'fields' => array_map(fn (WorkflowField $field): array => $field->toArray(), $action::fields()),
                'provides' => $action::provides(),
            ], $this->actions)),
        ];
    }

    /**
     * Two things answering to one key is not a thing to discover at runtime.
     *
     * The key is what a saved workflow points at, so a collision does not mean
     * "one of these is unreachable" — it means whichever was registered last
     * silently takes over workflows written for the other.
     *
     * @param  list<string>  $taken
     */
    private function guardAgainstDuplicate(string $key, array $taken, string $what): void
    {
        if (in_array($key, $taken, true)) {
            throw new InvalidArgumentException("Er is al een {$what} met de sleutel '{$key}'.");
        }
    }
}
