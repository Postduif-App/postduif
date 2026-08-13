<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The signed PDF, fetched by a machine that was told about it.
 *
 * The one route in this feature with no session, no token and no policy behind
 * it — the signature in the query string is the whole of the credential, and
 * the route is registered behind Laravel's `signed` middleware, which refuses
 * anything that was tampered with or has run out.
 *
 * Which is a deliberate trade, and worth saying out loud. Everything else that
 * hands out a contract asks who is asking: the chat download runs through
 * ContractPolicy, the signer's own copy is bound to their token. This one is
 * given to whoever we posted a webhook to, and it has to work from a server
 * with no account here — see DeliverContractWebhookJob::documentUrl for why the
 * alternative, a personal API token, is the far larger key.
 *
 * What keeps that honest is that the link is made in one place only, that it
 * expires, and that it opens exactly one document and nothing else. A leaked URL
 * costs one PDF for a week; a leaked API token costs the workspace.
 */
class ContractDocumentController extends Controller
{
    /**
     * The route name, named here rather than typed as a string in the job.
     *
     * The URL is signed, so a rename that the caller did not follow is not a
     * 404 somebody notices on the next deploy — it is a delivery that goes out
     * with no link in it, quietly, for as long as nobody looks.
     */
    public const ROUTE = 'contracts.document';

    public function __invoke(Contract $contract): BinaryFileResponse
    {
        /*
         * The status is asked as well as the file, because the two can disagree
         * for a moment: a contract that was completed and has since been pruned
         * of its evidence, or one whose signed copy is still being composed.
         * Both are a 404 rather than an error — the link was valid, the document
         * is not there.
         */
        abort_unless($contract->status->isEvidence(), 404);

        $media = $contract->signedCopy();

        abort_if($media === null || ! is_file($media->getPath()), 404);

        $response = response()->file($media->getPath(), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
            // Belt and braces on a file that is always a PDF and is served to
            // something that will believe whatever we say it is.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
