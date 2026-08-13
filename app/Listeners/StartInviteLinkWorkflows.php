<?php

namespace App\Listeners;

use App\Events\InviteLinkRedeemed;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\InviteLinkRedeemedTrigger;

/**
 * Set off the workflows that were waiting for somebody to come in through a
 * link.
 *
 * @extends StartsWorkflows<InviteLinkRedeemed>
 */
class StartInviteLinkWorkflows extends StartsWorkflows
{
    public function handle(InviteLinkRedeemed $event): void
    {
        $this->start($event);
    }

    protected function trigger(): string
    {
        return InviteLinkRedeemedTrigger::class;
    }

    /**
     * @param  InviteLinkRedeemed  $event
     */
    protected function workspaceOf(object $event): ?Workspace
    {
        return InviteLink::query()->with('workspace')->find($event->inviteLinkId)?->workspace;
    }

    /**
     * @param  InviteLinkRedeemed  $event
     * @return array<string, mixed>|null
     */
    protected function contextFor(Workflow $workflow, object $event): ?array
    {
        $link = InviteLink::query()
            ->with(['workspaceRole', 'workspace'])
            ->find($event->inviteLinkId);

        $user = User::find($event->userId);

        if ($link === null || $user === null) {
            return null;
        }

        $wanted = trim((string) $workflow->triggerSetting('role', ''));

        /*
         * Compared by name and without regard for case, because that is what
         * somebody types. A role that has since been renamed stops matching,
         * which is the honest failure: the workflow was written about a role
         * that is no longer called that.
         */
        if ($wanted !== '' && mb_strtolower($wanted) !== mb_strtolower((string) $link->workspaceRole?->name)) {
            return null;
        }

        return [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'link' => [
                'id' => $link->id,
                'role' => $link->workspaceRole?->name,
                'uses' => $link->uses,
                /*
                 * Empty for a link with no ceiling, which is not the same as
                 * nought and would read as "op" if it were.
                 */
                'uses_left' => $link->max_uses === null ? null : max(0, $link->max_uses - $link->uses),
            ],
        ];
    }
}
