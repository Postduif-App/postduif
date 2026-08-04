<?php

namespace App\Enums;

/**
 * Where one walk through a workflow's steps stands.
 *
 * Five, and two are worth explaining. Without Waiting a delayed workflow would
 * sit in Running for an hour, and "running" would stop meaning anything — there
 * would be no telling a workflow that is deliberately biding its time from one
 * whose worker died halfway.
 *
 * Stopped is not a flavour of Succeeded and not a flavour of Failed, for the
 * same reason Skipped is neither on a step: a run that a condition cut short
 * did the right thing and did not finish, and the run screen has to be able to
 * say which of the two happened to a workflow that went quiet.
 */
enum WorkflowRunStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Stopped = 'stopped';
    case Failed = 'failed';

    /** Whether this run still has something ahead of it. */
    public function isOpen(): bool
    {
        return $this === self::Running || $this === self::Waiting;
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => __('enums.workflow-run-status.label.Running'),
            self::Waiting => __('enums.workflow-run-status.label.Waiting'),
            self::Succeeded => __('enums.workflow-run-status.label.Succeeded'),
            self::Stopped => __('enums.workflow-run-status.label.Stopped'),
            self::Failed => __('enums.workflow-run-status.label.Failed'),
        };
    }
}
