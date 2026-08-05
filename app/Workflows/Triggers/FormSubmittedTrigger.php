<?php

namespace App\Workflows\Triggers;

use App\Features\Forms;
use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody filled in this form and sent it.
 *
 * The one trigger whose vocabulary is not the same twice. Every other trigger
 * hands over the same paths whichever workspace it runs in; this one hands over
 * whatever questions the chosen form happens to ask, under the keys that form
 * gave them — see provides(), which can only name the half it knows in advance.
 */
class FormSubmittedTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.form-submitted.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.form-submitted.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * Required, unlike the channel on the keyword trigger, and for the
             * reason that makes this trigger different from the rest: the
             * answers arrive under keys the form itself invented, so
             * {{ trigger.answers.reden }} only means anything once a form is
             * named. A workflow left pointing at every form would run on the
             * sick-leave form and the lunch order alike, reach for a key that
             * exists in one of them, and quietly send half a sentence.
             *
             * "Every form" is also the thing least likely to be wanted: a form
             * is made for one purpose, and what somebody wants to do with the
             * answers is that purpose too.
             */
            WorkflowField::form(
                'form_id',
                __('workflows.triggers.form-submitted.form.label'),
                __('workflows.triggers.form-submitted.form.hint'),
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function provides(): array
    {
        return [
            'form.id' => __('workflows.provides.form.id'),
            'form.title' => __('workflows.provides.form.title'),
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),

            /*
             * The answers whole, and every answer on its own under
             * {{ trigger.answers.<sleutel> }} — the map FormSubmission::
             * keyedAnswers() hands over, keyed by the field key.
             *
             * Only the one word is offered here because the keys below it
             * belong to the form, and this method is static: it is asked what
             * the trigger provides before anybody has chosen which form. Naming
             * the keys of one form would be a promise the next form breaks, so
             * the picker offers the word and the hint says where to find the
             * keys — the same bargain the webhook trigger strikes with payload.
             */
            'answers' => __('workflows.provides.answers'),
        ];
    }

    /**
     * Only where forms exist at all.
     *
     * Same shape as the webhook trigger's answer and the same reasoning: a
     * workspace that has switched forms off has no forms to point this at, and
     * a trigger offering an empty picker is worse than one that is not there.
     */
    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(Forms::class) ?? false;
    }
}
