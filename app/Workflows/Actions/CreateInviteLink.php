<?php

namespace App\Workflows\Actions;

use App\Actions\Workspace\CreateInviteLink as MintLink;
use App\Enums\WorkspaceAbility;
use App\Features\InviteLinks;
use App\Models\Role;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Mint a way in, and hand the address to the next step.
 *
 * The onboarding half that the redeemed trigger is the other end of: a form
 * comes in from a new customer, and the workflow makes them a link and mails it
 * — with a ceiling on how often it may be used and a date it stops working, so
 * an address that ends up in the wrong inbox is not a way into the workspace
 * forever.
 *
 * The role is named rather than picked, and that is a limitation of the field
 * types rather than a choice: a picker's options are declared on the class and
 * a workspace's roles are its own. Compared by name without regard for case, and
 * a name nobody recognises stops the step rather than falling back to something
 * — "gast" misspelt must not quietly become a colleague.
 *
 * It sends nothing. The link is a fact a following step puts in a message, a
 * mail or a ticket, which is what keeps this action out of the business of
 * knowing who should receive it.
 */
class CreateInviteLink extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly MintLink $mintLink) {}

    public static function label(): string
    {
        return __('workflows.actions.create-invite-link.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-invite-link.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text(
                'role',
                __('workflows.actions.create-invite-link.role.label'),
                __('workflows.actions.create-invite-link.role.hint'),
            ),
            /*
             * Both optional, and both worth filling in. A link with no ceiling
             * and no end date is a permanent way into a workspace, handed out
             * by something nobody is watching.
             */
            WorkflowField::number(
                'max_uses',
                __('workflows.actions.create-invite-link.uses.label'),
                __('workflows.actions.create-invite-link.uses.hint'),
                required: false,
            ),
            WorkflowField::number(
                'valid_for_days',
                __('workflows.actions.create-invite-link.days.label'),
                __('workflows.actions.create-invite-link.days.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'link.id' => __('workflows.provides.link.id'),
            'link.url' => __('workflows.provides.link.url'),
            'link.role' => __('workflows.provides.link.role'),
            'link.expires_at' => __('workflows.provides.link.expires_at'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(InviteLinks::class)) {
            throw new RuntimeException(__('workflows.errors.invite_links_off'));
        }

        $creator = $this->actor($context);

        /*
         * The ability rather than a policy, which is what invites are checked
         * against everywhere else. Asked of the workflow's owner at the moment
         * of running: somebody who has lost the right to invite people takes
         * their workflows' right with them.
         */
        if (! $context->workspace()->allows($creator, WorkspaceAbility::InviteMembers)) {
            throw new RuntimeException(__('workflows.errors.may_not_invite'));
        }

        $role = $this->role($context);

        $link = $this->mintLink->handle(
            workspace: $context->workspace(),
            creator: $creator,
            role: $role,
            maxUses: $this->bounded($context, 'max_uses', 1000),
            validForDays: $this->bounded($context, 'valid_for_days', 365),
        );

        return [
            'link' => [
                'id' => $link->id,
                'url' => route('invite-links.show', $link->token),
                'role' => $role->name,
                'expires_at' => $link->expires_at?->toDateString(),
            ],
        ];
    }

    /**
     * The role this link hands out, by the name somebody typed.
     */
    private function role(WorkflowStepContext $context): Role
    {
        $named = trim((string) $context->setting('role', ''));

        if ($named === '') {
            throw new RuntimeException(__('workflows.errors.no_role_named'));
        }

        $role = $context->workspace()->roles()
            ->whereRaw('lower(name) = ?', [mb_strtolower($named)])
            ->first();

        if ($role === null) {
            throw new RuntimeException(__('workflows.errors.role_not_found', ['role' => $named]));
        }

        return $role;
    }

    /**
     * A number from the step, or nothing — bounded either way.
     *
     * The value came out of a JSON column that an older version of this action
     * may have written, and a link valid for ten thousand days is a link
     * nobody meant to make.
     */
    private function bounded(WorkflowStepContext $context, string $key, int $ceiling): ?int
    {
        $value = $context->setting($key);

        if (blank($value) || ! is_numeric($value)) {
            return null;
        }

        return max(1, min($ceiling, (int) $value));
    }
}
