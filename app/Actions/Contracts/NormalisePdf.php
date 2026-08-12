<?php

namespace App\Actions\Contracts;

use Illuminate\Support\Facades\Process;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use setasign\Fpdi\Tcpdf\Fpdi;
use Throwable;

/**
 * Rewrite an uploaded PDF into the one thing this feature can safely work with.
 *
 * Three jobs at once, and they are one step rather than three because they all
 * fall out of the same pass through Ghostscript's pdfwrite device, which
 * rebuilds a PDF from its page content and discards everything it does not
 * understand as page content.
 *
 * What that discards is the point. A PDF is an executable format: it can carry
 * document-level JavaScript, /Launch actions and entire files embedded inside
 * it. This particular document is going to be mailed to people outside the
 * workspace with "hier moet je tekenen" next to it, which is about the most
 * trusting frame of mind anybody opens a file in. Rebuilding it is how that
 * trust is earned rather than assumed.
 *
 * The second job is version. The free FPDI parser reads PDF up to 1.4, and
 * everything a modern word processor produces is newer. Writing 1.4 here means
 * the overlay that composes the signed copy will work — and, far more
 * importantly, that a file it *cannot* work with is refused now, while the
 * author is still standing at the upload screen. The alternative is discovering
 * it on the queue after somebody has already signed, which leaves a valid
 * signature under a document nobody can produce.
 *
 * The third is the page count, taken from the rewritten file because that is
 * the file everything downstream lays boxes over.
 *
 * @phpstan-type NormalisedPdf array{path: string, pages: int, hash: string}
 */
class NormalisePdf
{
    /**
     * Constructs that have no business in a document somebody is asked to sign.
     *
     * Checked on the output rather than the input, which is the whole trick:
     * this is not a scanner trying to recognise every way a PDF can be nasty —
     * that is a losing game — but a check that the rewrite did what it was
     * supposed to. Ghostscript is what removes these; this is what refuses to
     * take its word for it.
     *
     * /OpenAction is deliberately *not* on this list, although an action that
     * fires on opening sounds like exactly the thing to refuse. Ghostscript
     * writes one into every file it produces — /OpenAction [4 0 R /FitH null],
     * meaning "open on this page, fit the width" — so forbidding it would
     * reject every document this feature has ever normalised, including the
     * clean ones. What made an open-action dangerous is the /JavaScript it
     * could point at, and that is two entries up.
     *
     * @var list<string>
     */
    private const FORBIDDEN = ['/JavaScript', '/JS', '/EmbeddedFile', '/Launch'];

    /**
     * The list, behind a method so a test can move the goalposts.
     *
     * Checking that the backstop fires means producing a file that gets past
     * Ghostscript and still trips the check — which is, by construction,
     * something we do not know how to make. Widening the list to something
     * every PDF contains tests the part that is actually ours: that the check
     * runs against the output and refuses when it matches.
     *
     * @return list<string>
     */
    protected function forbidden(): array
    {
        return self::FORBIDDEN;
    }

    /**
     * @return NormalisedPdf The rewritten file's temporary path, its page count
     *                       and the sha256 that will stand for it.
     *
     * @throws PdfRefused When the file is not a PDF we will put a signature on.
     */
    public function handle(string $sourcePath): array
    {
        $this->assertLooksLikePdf($sourcePath);

        $target = $this->rewrite($sourcePath);

        try {
            $this->assertNothingExecutableSurvived($target);

            $pages = $this->countPages($target);

            /*
             * The hash is taken here, over the rewritten file, and not over what
             * was uploaded.
             *
             * It has to be, because the rewritten file is the one that gets
             * signed: a hash of the upload would prove something about a
             * document nobody ever saw. This is what "getekend onder dít
             * document" is checked against at signing time.
             */
            $hash = hash_file('sha256', $target);
        } catch (Throwable $exception) {
            @unlink($target);

            throw $exception;
        }

        return ['path' => $target, 'pages' => $pages, 'hash' => (string) $hash];
    }

    /**
     * The cheap checks, before a process is started.
     *
     * finfo rather than the browser's word for it: the content type on an
     * upload is whatever the client felt like sending. The header check on top
     * of that is not redundant — finfo recognises a PDF by that header, so this
     * is really about giving a clearer refusal for the near miss where somebody
     * uploaded a Word document with the wrong extension.
     */
    private function assertLooksLikePdf(string $path): void
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new PdfRefused(__('contracts.upload.empty'));
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($mime !== 'application/pdf') {
            throw new PdfRefused(__('contracts.upload.not-a-pdf'));
        }

        $handle = fopen($path, 'rb');
        $header = $handle === false ? '' : (string) fread($handle, 5);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($header !== '%PDF-') {
            throw new PdfRefused(__('contracts.upload.not-a-pdf'));
        }
    }

    /** Run the file through Ghostscript and hand back where the result landed. */
    private function rewrite(string $path): string
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'contract-');

        $result = Process::timeout((int) config('contracts.normalise_timeout'))
            ->run([
                (string) config('contracts.ghostscript'),

                /*
                 * -dSAFER is not optional here and is worth naming out loud: it
                 * is what stops a hostile PDF from using the rewriter itself to
                 * read or write files on this server. Modern Ghostscript has it
                 * on by default; passing it means a slightly older build on some
                 * server does too.
                 */
                '-dSAFER',
                '-dNOPAUSE',
                '-dBATCH',
                '-dQUIET',
                '-sDEVICE=pdfwrite',

                /*
                 * Keep the page boxes as they are. The overlay works in the
                 * page's own coordinate space, so a rewriter that helpfully
                 * scaled everything to A4 would move every signature box on a
                 * document that happened to be Letter or A3.
                 */
                '-dPDFSETTINGS=/prepress',
                '-dAutoRotatePages=/None',

                /*
                 * The version FPDI can read — see the class docblock.
                 *
                 * After -dPDFSETTINGS on purpose: that switch sets a
                 * compatibility level of its own, and Ghostscript takes the last
                 * word. Put this first and a later preset would quietly override
                 * the one thing this call exists to guarantee.
                 */
                '-dCompatibilityLevel=1.4',

                '-sOutputFile='.$target,
                $path,
            ]);

        if (! $result->successful() || filesize($target) === 0) {
            @unlink($target);

            /*
             * One message for two rather different failures — Ghostscript is
             * missing, or Ghostscript could not make sense of the file — and
             * that is on purpose at this level. The author can do nothing about
             * either except try another file, and telling a customer's employee
             * which binary is not installed on our server is not information
             * they should be handed.
             *
             * The real reason goes to the log through the process output, which
             * is where somebody who can fix it will be looking.
             */
            report(new PdfRefused('Ghostscript refused the upload: '.$result->errorOutput()));

            throw new PdfRefused(__('contracts.upload.unreadable'));
        }

        return $target;
    }

    /**
     * Check that the rewrite actually removed what it was meant to.
     *
     * A plain byte search over the whole file, which is blunt on purpose. It
     * will occasionally refuse a harmless document whose text happens to contain
     * "/JavaScript" — and that is the right way round for this trade: the cost
     * of a false refusal is an author picking another file, the cost of a false
     * pass is an executable document mailed out under this workspace's name.
     */
    private function assertNothingExecutableSurvived(string $path): void
    {
        $contents = (string) file_get_contents($path);

        foreach ($this->forbidden() as $marker) {
            if (str_contains($contents, $marker)) {
                throw new PdfRefused(__('contracts.upload.executable'));
            }
        }
    }

    /**
     * How many pages the rewritten file has.
     *
     * Read through FPDI rather than by counting /Type /Page in the bytes, and
     * the reason is not accuracy but agreement: FPDI is what will later import
     * those pages one by one, so asking it now means the number stored is the
     * number the renderer will be able to reach. A count that disagreed with it
     * would show the author a page in the editor that the signed copy silently
     * dropped.
     *
     * That it can fail at all is itself the check worth having — this is the
     * moment we learn the overlay will work.
     *
     * The TCPDF flavour of Fpdi rather than the plain one, and it has to be:
     * \setasign\Fpdi\Fpdi extends FPDF, which is not installed and is not what
     * composes the signed copy. Using the wrong one here would parse with a
     * different backend than the renderer does, which is the one thing this
     * method exists to rule out.
     */
    private function countPages(string $path): int
    {
        try {
            $pdf = new Fpdi;
            $pages = $pdf->setSourceFile($path);
        } catch (CrossReferenceException|PdfParserException|PdfTypeException $exception) {
            report($exception);

            throw new PdfRefused(__('contracts.upload.unreadable'));
        }

        if ($pages < 1) {
            throw new PdfRefused(__('contracts.upload.empty'));
        }

        if ($pages > (int) config('contracts.max_pages')) {
            throw new PdfRefused(__('contracts.upload.too-many-pages', [
                'max' => (int) config('contracts.max_pages'),
            ]));
        }

        return $pages;
    }
}
