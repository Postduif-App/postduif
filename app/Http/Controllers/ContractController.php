<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\PdfRefused;
use App\Http\Requests\StoreContractRequest;
use App\Models\Contract;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Starting a contract, and handing its document back out.
 *
 * Only the two ends that the upload needs. Drawing the boxes, naming the
 * signers and everything after that live elsewhere; what this file has to get
 * right is that a PDF gets in safely and comes back out to the right people.
 */
class ContractController extends Controller
{
    /**
     * Take the uploaded PDF and open a draft on it.
     *
     * A redirect rather than JSON, because this is a form being submitted and
     * the next thing that happens is a whole new screen — the editor, with the
     * document rendered in it.
     */
    public function store(
        StoreContractRequest $request,
        Workspace $workspace,
        CreateContract $createContract,
    ): RedirectResponse {
        try {
            $contract = $createContract->handle(
                workspace: $workspace,
                author: $request->user(),
                file: $request->file('file'),
                title: $request->string('title')->toString(),
                message: $request->input('message'),
                validForDays: $request->integer('valid_for_days') ?: null,
            );
        } catch (PdfRefused $refused) {
            /*
             * Back to the form with the reason on the file field, which is where
             * the person is looking.
             *
             * A refusal here is not an error in the sense that anything went
             * wrong — it is validation that happened to need Ghostscript to
             * reach a verdict, and it should read to the author exactly like
             * "dit is geen PDF" does. Every message this can carry is written
             * for that — see the contracts.upload keys in the language files.
             */
            return back()
                ->withInput($request->except('file'))
                ->withErrors(['file' => $refused->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.contract.created'),
        ]);

        /*
         * Back with the id rather than on to the field editor, for now.
         *
         * The editor is the screen this ought to open — a contract without
         * boxes on it is not finished — but it is a piece of work of its own and
         * does not exist yet. Handing the id back means the page that submitted
         * this can go there the moment it does, and in the meantime the author
         * gets a draft rather than a redirect into a 404.
         */
        return back()->with('contractId', $contract->id);
    }

    /**
     * The PDF itself, for the editor and for the signing page to render.
     *
     * Inline rather than as a download, because both of those load it into
     * pdf.js rather than offering it to the reader — ?download=1 is there for
     * the author who wants the file itself.
     *
     * Streamed from the private disk through a policy, and there is no other
     * way to it. That is the whole reason this method exists: a contract on a
     * public disk would be one guessed URL away from a stranger, and the URL
     * would keep working after the contract was withdrawn, which is every limit
     * the feature has.
     */
    public function source(
        Request $request,
        Workspace $workspace,
        Contract $contract,
    ): BinaryFileResponse {
        abort_unless($contract->workspace_id === $workspace->id, 404);

        $this->authorize('download', $contract);

        $media = $contract->source();

        abort_if($media === null, 404);

        $path = $media->getPath();

        abort_unless(is_file($path), 404);

        $inline = ! $request->boolean('download');

        $response = response()->file($path, [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($media->file_name).'"',

            /*
             * No guessing around the type. This route sits on the application's
             * own origin, and a browser that sniffed its way to "this is really
             * HTML" would run whatever it found there as us.
             *
             * That should be impossible — NormalisePdf rewrites every upload
             * through Ghostscript and refuses anything executable that survives
             * — but the whole point of that pass is that a PDF is not to be
             * taken at its word, and neither is this file just because it went
             * through it.
             */
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set on the response rather than handed in: a file response fills in
        // Content-Type from the bytes on disk, which is a second opinion we did
        // not ask for.
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
