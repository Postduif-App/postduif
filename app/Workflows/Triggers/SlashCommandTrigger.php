<?php

namespace App\Workflows\Triggers;

use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;
use Illuminate\Support\Str;

/**
 * Somebody typed /iets in the message field.
 *
 * The other manual trigger beside LinkTrigger, and the difference is where the
 * gesture starts. The link trigger is reached from a message that already
 * exists — it is a thing you do *to* something. This one starts from an empty
 * field: you know what you want to set off before there is anything to point
 * at, which is the case the message menu cannot serve.
 *
 * What follows the command travels along as `arguments`, one string, uncut. It
 * is not parsed into words here on purpose: a workflow that wants the first
 * word can take it, and one that wants a sentence would never get it back
 * together again from a list.
 */
class SlashCommandTrigger extends WorkflowTrigger
{
    /**
     * The commands the conversation already answers to itself.
     *
     * Spelled again here rather than shared, because the other copy is a list of
     * React callbacks in conversation.tsx and there is nothing to share it
     * *through* — a command is a name plus something to run, and neither half
     * crosses to the other side. What crosses is the refusal: a workflow may not
     * take a name the composer already spends, or one of the two would silently
     * win. Adding a command over there means adding it here.
     *
     * @var list<string>
     */
    public const RESERVED = ['versturen', 'geheim', 'geheim-sturen', 'poll'];

    /** The longest a command may be, counted without the slash. */
    public const MAX_LENGTH = 32;

    public static function label(): string
    {
        return __('workflows.triggers.slash-command.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.slash-command.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text(
                'command',
                __('workflows.triggers.slash-command.command.label'),
                __('workflows.triggers.slash-command.command.hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'command' => __('workflows.provides.command'),
            'arguments' => __('workflows.provides.arguments'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'user.id' => __('workflows.provides.starter.id'),
            'user.name' => __('workflows.provides.starter.name'),
        ];
    }

    /**
     * What somebody typed, as it will be stored and matched.
     *
     * The slash goes: it is punctuation in the composer, not part of the name,
     * and half of the people filling this field in will type it anyway. Case
     * and stray spaces go for the same reason — "/Storing " and "/storing" are
     * one command as far as anybody typing them is concerned.
     */
    public static function normalise(string $command): string
    {
        return Str::of($command)
            ->trim()
            ->ltrim('/')
            ->trim()
            ->lower()
            ->value();
    }

    /**
     * Whether this is a name a command may have at all.
     *
     * Letters, digits and hyphens, starting on a letter or a digit. No spaces:
     * everything after the first one is the arguments, so a command with a
     * space in it is a command that can never be matched.
     */
    public static function isWellFormed(string $command): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,'.(self::MAX_LENGTH - 1).'}$/', $command) === 1;
    }

    /**
     * Whether another workflow in this workspace already answers to a command.
     *
     * Disabled ones count. A name that is free today and clashes the moment
     * somebody switches an old workflow back on is worse than a name refused
     * now, while the person is still looking at the field.
     *
     * The trigger type is asked as well as the config: a workflow that was a
     * slash command last week still has the command sitting in its
     * trigger_config, and the column is what says what it is now.
     *
     * Compared in PHP rather than through the JSON column, because what has to
     * match is the *normalised* command and a query cannot normalise. The list
     * it walks is capped at WorkflowController::MAX_WORKFLOWS per workspace.
     */
    public static function taken(Workflow $workflow, string $command): bool
    {
        return $workflow->workspace
            ->workflows()
            ->where('trigger_type', self::key())
            ->whereKeyNot($workflow->getKey())
            ->get()
            ->contains(fn (Workflow $other): bool => self::normalise(
                (string) $other->triggerSetting('command', ''),
            ) === $command);
    }
}
