<?php

namespace App\Jobs;

use App\Actions\Contracts\RenderSignedContract;
use App\Models\Contract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Make the signed copy, away from the request that finished the contract.
 *
 * On a queue because it costs seconds rather than milliseconds — a twenty-page
 * document is twenty page imports and a font to subset — and the person who
 * pressed "ondertekenen" should not sit watching a spinner for it. What they
 * were doing is over; this is bookkeeping that follows.
 *
 * The important property is what happens when it fails, and it is the reason
 * this is a job at all rather than a step at the end of SignContract. A
 * signature that has been given must never be lost because a rendering step
 * stumbled. So the contract is already Completed before this runs, the failure
 * is recorded beside the status rather than in it, and the whole thing can be
 * tried again later without anybody having to sign anything twice.
 */
class RenderSignedContractJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Three goes, spread out.
     *
     * Worth retrying because the ways this fails are mostly temporary: a disk
     * that was full, a worker killed mid-run. Not worth retrying forever,
     * because the ways it fails permanently — a source document that has
     * changed — will fail identically at the tenth attempt and the overview
     * should say so rather than keep pretending.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public readonly string $contractId) {}

    public function handle(RenderSignedContract $render): void
    {
        $contract = Contract::query()->with(['fields', 'signers', 'workspace', 'author'])->find($this->contractId);

        if ($contract === null) {
            // Deleted between the dispatch and the run. Nothing to make, and
            // nothing wrong either.
            return;
        }

        $render->handle($contract);

        /*
         * Cleared on success, so the column always means "the last attempt
         * failed" rather than "something failed once, ages ago". An overview
         * that keeps flagging a contract whose PDF is sitting right there is an
         * overview people stop reading.
         */
        if ($contract->render_failed_at !== null) {
            $contract->forceFill(['render_failed_at' => null])->save();
        }
    }

    /**
     * After the last attempt, say so on the row.
     *
     * Not on the status, which still says Completed and should: the contract is
     * signed and that fact is complete and unharmed. This is the flag the
     * overview reads to offer "opnieuw proberen" beside an otherwise finished
     * contract.
     */
    public function failed(?Throwable $exception): void
    {
        Contract::query()
            ->whereKey($this->contractId)
            ->update(['render_failed_at' => now()]);
    }

    /**
     * One job per contract in flight.
     *
     * Two runs composing the same document would race to write the same
     * single-file collection, and the loser's bytes would be the ones on disk.
     * Both would be correct documents, which is exactly what makes that kind of
     * race hard to notice.
     */
    public function uniqueId(): string
    {
        return $this->contractId;
    }
}
