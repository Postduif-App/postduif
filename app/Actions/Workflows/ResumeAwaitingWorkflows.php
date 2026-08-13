<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowAwaitableEvent;
use App\Enums\WorkflowRunStatus;
use App\Jobs\RunWorkflowJob;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The second way into a waiting run: the happening it was waiting for.
 *
 * Until this existed there was one — resume_at, the clock — and everything a
 * workflow wanted to wait for had to be expressed as a length of time. "Wacht
 * drie dagen en kijk dan of er getekend is" is a poor substitute for "wacht tot
 * er getekend is": it acts three days late in the good case and needs a second
 * workflow to cover the bad one.
 *
 * Called from StartMatchingWorkflows, which is the one place every happening
 * already passes through on its way to starting a workflow. That is the whole
 * reason waiting costs nothing per feature: a trigger that can start a run can
 * now finish a wait, and no listener had to learn about either.
 *
 * Claimed inside a transaction, the same shape as the clock's sweep: the run is
 * moved out of Waiting before anything is dispatched, so a second copy of the
 * same event — two signers finishing in the same second, a queue retrying —
 * finds nothing left to wake.
 */
class ResumeAwaitingWorkflows
{
    /**
     * @param  array<string, mixed>  $happening  What the trigger saw, as its provides() describes it.
     * @return int How many runs were sent back to a worker.
     */
    public function handle(Workspace $workspace, string $triggerKey, array $happening): int
    {
        $event = WorkflowAwaitableEvent::tryFrom($triggerKey);

        /*
         * Most happenings are not waitable — a keyword in a message is
         * something you react to, not something you wait for — and for those
         * this is a single enum lookup and out. See the enum for the rule.
         */
        if ($event === null) {
            return 0;
        }

        $record = data_get($happening, $event->pathInHappening());

        /*
         * A happening with no record in it cannot end anybody's wait: what a
         * waiting run holds is "this contract", and matching on the event alone
         * would wake every run in the workspace the next time any contract was
         * signed.
         */
        if (blank($record)) {
            return 0;
        }

        $woken = $this->claim($workspace, $event->value, (string) $record);

        foreach ($woken as $id) {
            RunWorkflowJob::dispatch($id);
        }

        return count($woken);
    }

    /**
     * @return list<int>
     */
    private function claim(Workspace $workspace, string $event, string $record): array
    {
        return DB::transaction(function () use ($workspace, $event, $record): array {
            $runs = WorkflowRun::query()
                ->awaiting($event, $record)
                /*
                 * Scoped to the workspace, and that is not a formality: ids
                 * repeat across workspaces — ticket 4 exists in every one of
                 * them — so a match on the event and the id alone would wake a
                 * stranger's workflow on the strength of a coincidence.
                 */
                ->whereHas('workflow', fn ($query) => $query->where('workspace_id', $workspace->id))
                ->lockForUpdate()
                ->get();

            foreach ($runs as $run) {
                /*
                 * Stamped rather than cleared. The runner reads this on the way
                 * back in to work out which of the two ended the wait — the
                 * event or the deadline — and a run whose await was simply
                 * removed here would be indistinguishable from one the clock
                 * caught up with. See RunWorkflow::settleAwait.
                 */
                $run->forceFill([
                    'status' => WorkflowRunStatus::Running,
                    'resume_at' => null,
                    'awaiting' => [...$run->awaiting, 'happened' => true],
                ])->save();
            }

            return array_values($runs->pluck('id')->map(intval(...))->all());
        });
    }
}
