<?php

namespace App\Console\Commands;

use App\Actions\Contracts\NormalisePdf;
use App\Actions\Contracts\PdfRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;
use Throwable;

/**
 * Prove that this machine can actually process a contract.
 *
 * Written because of how this fails otherwise: a server without Ghostscript
 * looks perfectly healthy. Every page renders, every test that CI runs is
 * green — the suite skips the upload tests when the binary is missing — and
 * nothing at all is wrong until somebody drags in a PDF and is told their
 * document is damaged. It was, in fact, exactly that afternoon that produced
 * this command.
 *
 * So it does the real thing rather than asking whether a file exists: it makes
 * a small PDF, puts it through the same action every upload goes through, and
 * reads the result back with the same parser the signed copy is composed with.
 * A check that only looked for the binary would pass on a Ghostscript too old
 * to write 1.4, or one that refuses to run under -dSAFER.
 *
 * Exits non-zero when it cannot, so a deploy script can be told to care.
 */
#[Signature('contracts:check')]
#[Description('Check that this machine can normalise and read a contract PDF')]
class CheckContractPipeline extends Command
{
    public function handle(NormalisePdf $normalise): int
    {
        $binary = (string) config('contracts.ghostscript');

        $this->line('Ghostscript: <comment>'.$binary.'</comment>');

        $version = $this->version($binary);

        if ($version === null) {
            $this->error(__('console.contracts_check_missing', ['binary' => $binary]));
            $this->line(__('console.contracts_check_hint'));

            return self::FAILURE;
        }

        $this->line('Versie: <comment>'.$version.'</comment>');

        $sample = $this->sample();

        try {
            $result = $normalise->handle($sample);
        } catch (PdfRefused $refused) {
            $this->error(__('console.contracts_check_failed', ['reason' => $refused->getMessage()]));

            return self::FAILURE;
        } finally {
            @unlink($sample);
        }

        /*
         * Read back with the parser that composes the signed copy, not with the
         * one that counted the pages a moment ago — they are the same class,
         * and that is the point: if this can open it, so can the renderer.
         */
        try {
            $pages = (new Fpdi)->setSourceFile($result['path']);
        } catch (Throwable $exception) {
            $this->error(__('console.contracts_check_unreadable', ['reason' => $exception->getMessage()]));

            return self::FAILURE;
        } finally {
            @unlink($result['path']);
        }

        $this->info(__('console.contracts_check_ok', ['pages' => $pages]));

        return self::SUCCESS;
    }

    /**
     * What Ghostscript says it is, or null when it will not run at all.
     *
     * The version is printed rather than judged. Every release since 9.50 can
     * do what this needs, and pinning a floor here would be inventing a rule
     * nobody has hit — what is worth having on screen is the number, so that
     * somebody reading a failure elsewhere can see it without going to look.
     */
    private function version(string $binary): ?string
    {
        $result = Process::timeout(10)->run([$binary, '--version']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * A PDF of one page, made here rather than kept in the repository.
     *
     * A fixture would be a binary file nobody could review, and it would age:
     * this one is produced by the same library that composes the signed copy,
     * so it is always a document this application could plausibly be handed.
     */
    private function sample(): string
    {
        $pdf = new TCPDF;
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'contracts:check');

        $path = (string) tempnam(sys_get_temp_dir(), 'contract-check-').'.pdf';

        $pdf->Output($path, 'F');

        return $path;
    }
}
