<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Features\WorkspaceFeature;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\RecordSnapshot;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Read a record again, and put what it says now under this step.
 *
 * The answer to the thing that made a Delay misleading. A run's context is a
 * photograph: StartWorkflow writes down what the trigger saw and nothing
 * touches it afterwards. For a workflow that runs straight through that is
 * exactly right — every step should agree about what set it off. Put a Delay in
 * the middle and it stops being right: "wacht drie dagen, en als er dan nog
 * steeds niemand getekend heeft, meld het" was unwritable, because
 * {{ trigger.contract.signed_count }} after three days is still the number from
 * three days ago and nothing on screen said so.
 *
 * So: an explicit step rather than conditions that quietly go and look. The
 * left-hand side of a condition stays a path and nothing else — see
 * EvaluateCondition, which rests on that — and the builder can see, in the list
 * of steps, exactly where the numbers were refreshed. The cost is that somebody
 * has to think of it, which is the trade this direction makes on purpose:
 * visible and forgettable beats invisible and surprising.
 *
 * What it hands back is spelled exactly like what the trigger hands over — see
 * RecordSnapshot, which both read from — so "{{ steps.2.contract.signed_count }}"
 * is the same fact as "{{ trigger.contract.signed_count }}", only current.
 *
 * Changes nothing, like GetChannelInfo. It is the second action in the whole
 * set that only looks.
 */
abstract class ReadRecord extends WorkflowAction
{
    use FindsTargets;

    /** Which kind of thing this one re-reads. */
    abstract protected static function type(): WorkflowRecordType;

    /**
     * The switch this kind of record lives behind, or null where there is none.
     *
     * @return class-string<WorkspaceFeature>|null
     */
    protected static function feature(): ?string
    {
        return null;
    }

    /** What the field is called, and the sentence under it. */
    abstract protected static function fieldLabel(): string;

    abstract protected static function fieldHint(): string;

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                static::type()->value.'_id',
                static::type(),
                static::fieldLabel(),
                static::fieldHint(),
            ),
        ];
    }

    /**
     * The same paths the matching trigger promises.
     *
     * That sameness is the whole point rather than a convenience: a builder who
     * knows {{ trigger.ticket.is_overdue }} already knows what this step gives
     * them, and a second vocabulary for "the current state of a ticket" would
     * be a second thing to learn for no gain.
     *
     * @return array<string, string>
     */
    public static function provides(): array
    {
        return RecordSnapshot::paths(static::type());
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $feature = static::feature();

        if ($feature !== null && ! $context->workspace()->hasFeature($feature)) {
            throw new RuntimeException(__('workflows.errors.record_feature_off', [
                'what' => static::type()->label(),
            ]));
        }

        /*
         * Through record() like every other step, so an empty field means the
         * record the trigger was about — which is what a re-read nearly always
         * means, and needs nothing typed. The workspace scoping and the
         * "may the owner see this" question are asked there, once.
         */
        return RecordSnapshot::of($this->record($context, static::type()));
    }
}
