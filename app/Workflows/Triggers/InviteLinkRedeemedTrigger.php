<?php

namespace App\Workflows\Triggers;

use App\Features\InviteLinks;
use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody joined through an invitation link.
 *
 * The onboarding trigger: a welcome message, a ticket for whoever has to set
 * them up, a document with the things a new colleague needs. ChannelMemberJoined
 * fires too — once per channel the link put them in — but that one is about a
 * room and says nothing about how they got in.
 *
 * The filter is the role the link hands out, not the link itself. Links have no
 * name — there is nothing to recognise one by in a list, and their ids turn over
 * as fast as somebody makes them — while the role is the thing a workflow
 * actually means: "wie als gast binnenkomt" is a different welcome from "wie als
 * collega binnenkomt". Left empty it means any link at all.
 */
class InviteLinkRedeemedTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.invite-link-redeemed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.invite-link-redeemed.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text(
                'role',
                __('workflows.triggers.invite-link-redeemed.role.label'),
                __('workflows.triggers.invite-link-redeemed.role.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
            'link.id' => __('workflows.provides.link.id'),
            'link.role' => __('workflows.provides.link.role'),
            'link.uses' => __('workflows.provides.link.uses'),
            'link.uses_left' => __('workflows.provides.link.uses_left'),
        ];
    }

    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(InviteLinks::class) ?? false;
    }
}
