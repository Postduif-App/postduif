<?php

namespace App\Workflows\Triggers;

use App\Features\Contracts;
use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * What the eight contract triggers have in common.
 *
 * Eight rather than one with a "wanneer" dropdown, and that was a real choice.
 * A single trigger would keep the menu shorter, but it would have to promise
 * the union of everything any of these carry — so a workflow watching for
 * "verstuurd" would be offered {{ trigger.signer.name }} in the variable picker
 * and get an empty string for it. The promise a trigger makes about its
 * variables is the whole basis of the builder, and eight honest promises beat
 * one that is only true some of the time.
 *
 * What they share is the filtering and the contract half of the payload, which
 * is what this class is. Three filters, all optional, and all meaning
 * "everything" when left alone: the channel news about the contract is posted
 * in, the colleague who asked for the signatures, and words in the title.
 * Together they are what keeps one workflow from firing on every contract a
 * busy workspace sends.
 */
abstract class ContractTrigger extends WorkflowTrigger
{
    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * The contract's notify channel, not a channel to post in. This is
             * a filter: "alleen de contracten die in #verkoop gemeld worden".
             */
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.contract.channel.label'),
                __('workflows.triggers.contract.channel.hint'),
                required: false,
            ),
            WorkflowField::member(
                'author_id',
                __('workflows.triggers.contract.author.label'),
                __('workflows.triggers.contract.author.hint'),
                required: false,
            ),
            /*
             * On the title, because a workspace's contracts are not all one
             * kind of thing and the title is what tells them apart. "offerte"
             * and "geheimhouding" want different workflows.
             */
            WorkflowField::words(
                'title_words',
                __('workflows.triggers.contract.words.label'),
                __('workflows.triggers.contract.words.hint'),
                required: false,
            ),
        ];
    }

    /**
     * Everything about the contract itself.
     *
     * The counts and days_until_expiry are worked out where the trigger data is
     * built rather than left to whoever writes the condition — see
     * StartContractWorkflows. That is deliberate: a condition can compare a
     * number but cannot produce one, so "verloopt binnen drie dagen" only
     * exists if something offers the number of days.
     *
     * @return array<string, string>
     */
    protected static function contractProvides(): array
    {
        return [
            'contract.id' => __('workflows.provides.contract.id'),
            'contract.title' => __('workflows.provides.contract.title'),
            'contract.status' => __('workflows.provides.contract.status'),
            'contract.url' => __('workflows.provides.contract.url'),
            'contract.expires_at' => __('workflows.provides.contract.expires_at'),
            'contract.days_until_expiry' => __('workflows.provides.contract.days_until_expiry'),
            'contract.page_count' => __('workflows.provides.contract.page_count'),
            'contract.signer_count' => __('workflows.provides.contract.signer_count'),
            'contract.signed_count' => __('workflows.provides.contract.signed_count'),
            'contract.declined_count' => __('workflows.provides.contract.declined_count'),
            'contract.remaining' => __('workflows.provides.contract.remaining'),
            'contract.signers' => __('workflows.provides.contract.signers'),
            'author.id' => __('workflows.provides.contract.author_id'),
            'author.name' => __('workflows.provides.contract.author_name'),
            'channel.id' => __('workflows.provides.contract.channel_id'),
            'channel.name' => __('workflows.provides.contract.channel_name'),
        ];
    }

    /**
     * And everything about the one person this happening was about.
     *
     * Only on the three triggers that have one. is_external is the one worth
     * pointing at: a signer with no account is somebody from outside, and
     * "schrijf een andere boodschap voor klanten dan voor collega's" is the
     * condition people reach for first.
     *
     * @return array<string, string>
     */
    protected static function signerProvides(): array
    {
        return [
            'signer.id' => __('workflows.provides.signer.id'),
            'signer.name' => __('workflows.provides.signer.name'),
            'signer.email' => __('workflows.provides.signer.email'),
            'signer.order' => __('workflows.provides.signer.order'),
            'signer.is_external' => __('workflows.provides.signer.is_external'),
            'signer.is_last' => __('workflows.provides.signer.is_last'),
        ];
    }

    /**
     * Only where the workspace asks for signatures at all.
     *
     * The same answer the form and timeclock triggers give: a trigger that can
     * never fire is worse than one that is not offered.
     */
    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(Contracts::class) ?? false;
    }
}
