<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Start a contract from an uploaded PDF.
 *
 * An action rather than a few lines in the controller, because the row and the
 * document are one thing: a contract whose row exists without its PDF is an
 * editor with nothing to draw on, and a PDF without a row is bytes nobody will
 * ever come back for.
 *
 * What it deliberately does *not* do is send anything. This leaves a draft — the
 * author still has to draw the boxes and name the people — and that separation
 * is what makes it safe for the expensive, failure-prone part to happen here:
 * if the PDF cannot be normalised, nothing has been promised to anybody yet.
 */
class CreateContract
{
    public function __construct(private NormalisePdf $normalise) {}

    /**
     * @param  UploadedFile  $file  The PDF as the browser sent it. What ends up
     *                              stored is a rewrite of this — see NormalisePdf.
     *
     * @throws PdfRefused When the upload is not something we will put a
     *                    signature on. Nothing is written when it is thrown.
     */
    public function handle(
        Workspace $workspace,
        User $author,
        UploadedFile $file,
        string $title,
        ?string $message = null,
        ?int $validForDays = null,
    ): Contract {
        /*
         * Outside the transaction, and first.
         *
         * It runs Ghostscript, which takes seconds on a large document, and a
         * transaction held open across a subprocess is a database connection
         * sitting idle with locks in its hand. It also throws for perfectly
         * ordinary reasons — wrong file type, too many pages — and there is
         * nothing to roll back when the answer is "kies een ander bestand".
         */
        $normalised = $this->normalise->handle($file->getRealPath());

        try {
            return DB::transaction(function () use ($workspace, $author, $title, $message, $validForDays, $normalised, $file): Contract {
                $contract = Contract::create([
                    'workspace_id' => $workspace->id,
                    'created_by' => $author->id,
                    'title' => $title,
                    'message' => $message,
                    'status' => ContractStatus::Draft,
                    'page_count' => $normalised['pages'],
                    'source_hash' => $normalised['hash'],
                    'expires_at' => $validForDays === null ? null : now()->addDays($validForDays),
                ]);

                /*
                 * The rewritten bytes go on the row, under the name the author
                 * uploaded.
                 *
                 * Those two halves are worth separating: what is stored is not
                 * what was uploaded — Ghostscript rebuilt it — but the name is,
                 * because "Huurovereenkomst 2026.pdf" is how the author and
                 * every signer will recognise the thing. A recipient who
                 * downloads it should get back the document they were told
                 * about, not contract-a3f9b1.pdf.
                 *
                 * preservingOriginal is off by default, which is what we want:
                 * the temporary file is moved rather than copied, so the
                 * rewrite does not linger in the system temp directory.
                 */
                $contract->addMedia($normalised['path'])
                    ->usingFileName($file->getClientOriginalName())
                    ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                    ->toMediaCollection(Contract::SOURCE);

                return $contract;
            });
        } catch (Throwable $exception) {
            /*
             * The media library moves the temporary file on success, so this
             * only ever has something to clean up when the transaction failed —
             * which is exactly the case where nobody is left holding a path to
             * it. Suppressed rather than guarded: the file being gone already is
             * the ordinary outcome, not a fault worth logging.
             */
            @unlink($normalised['path']);

            throw $exception;
        }
    }
}
