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
     * @param  bool  $asTemplate  Whether this document is being kept to be sent
     *                            again rather than sent. Decided here, at the
     *                            upload, because it is decided in the author's
     *                            head there — see ContractController::store.
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
        bool $asTemplate = false,
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
            return DB::transaction(function () use ($workspace, $author, $title, $message, $validForDays, $asTemplate, $normalised, $file): Contract {
                $contract = Contract::create([
                    'workspace_id' => $workspace->id,
                    'created_by' => $author->id,
                    'title' => $title,
                    'message' => $message,
                    'status' => ContractStatus::Draft,
                    'is_template' => $asTemplate,

                    /*
                     * One recipient to begin with, rather than the null that
                     * would mean "nog niet bepaald".
                     *
                     * Null is a state a template can be in — the API can make
                     * one, and isReadyToSend refuses it — but it is a poor place
                     * to start somebody off: the editor asks which party each
                     * box is for, and a template with no parties at all cannot
                     * answer that question even once. One recipient is also the
                     * ordinary shape of these documents, so the number on the
                     * template screen is usually already right.
                     */
                    'required_signers' => $asTemplate ? 1 : null,

                    'page_count' => $normalised['pages'],
                    'source_hash' => $normalised['hash'],

                    /*
                     * A template never runs out. The deadline belongs to the
                     * invitation, and a template is never sent to anybody — a
                     * mould that quietly expired would take a hundred contracts
                     * nobody has made yet with it.
                     */
                    'expires_at' => $validForDays === null || $asTemplate
                        ? null
                        : now()->addDays($validForDays),
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
