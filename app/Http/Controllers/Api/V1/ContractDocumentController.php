<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTokenWorkspace;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fetching the finished document.
 *
 * The last step of the round trip the epic asks for: a system sends a contract,
 * hears that it was signed, and comes back for the PDF with the signatures and
 * the audit page on it.
 *
 * Streamed from the private disk through this route rather than handed out as a
 * link to storage, for the reason the whole feature keeps the disk private: a
 * signed agreement one URL away from anybody who finds the URL is not a signed
 * agreement anybody should rely on. Every read passes the same two questions
 * the screens ask — is this your workspace, and may you see this row.
 */
class ContractDocumentController extends Controller
{
    use ResolvesTokenWorkspace;

    public function __invoke(Request $request, Contract $contract): BinaryFileResponse
    {
        $workspace = $this->workspaceFor($request);

        abort_if($contract->workspace_id !== $workspace->id, 404, __('contracts.api.no_contract'));
        abort_unless($request->user()->can('download', $contract), 404, __('contracts.api.no_contract'));

        $media = $contract->signedCopy();

        /*
         * 409 rather than 404, and the difference is the whole of what a caller
         * needs. There is nothing wrong with the address: the contract is real
         * and this is where its document will be. It is either not signed yet
         * or still being composed, and both are states that end — see
         * Contract::signedCopyState, which the status endpoint reports so a
         * caller can wait rather than poll blindly.
         */
        abort_if(
            $media === null || ! is_file($media->getPath()),
            409,
            __('contracts.api.no_signed_copy', ['state' => $contract->signedCopyState()]),
        );

        return response()->file($media->getPath(), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',

            // No guessing around the type, on our own origin. The same header
            // the screens' download carries, for the same reason.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
