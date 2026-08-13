<?php

namespace App\Actions\Contracts;

use App\Enums\ContractFieldType;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Compose the document people actually get: the contract with every answer on
 * it, and the audit trail bound to the back.
 *
 * The overlay approach, chosen in the decision bead: the original pages are
 * imported one by one and drawn over, so the text of the contract stays real
 * text rather than becoming a picture of itself. What makes that possible is
 * that every upload has already been rewritten to PDF 1.4 by Ghostscript — see
 * NormalisePdf, which exists for this moment as much as for the security.
 *
 * The audit page at the back is not decoration. An overlay proves nothing on
 * its own: a name printed on a line is a name printed on a line. What makes the
 * whole thing defensible is the page that says who was asked, when each of them
 * answered, from where, and — the part that matters most — the sha256 of the
 * document they were looking at when they did.
 */
class RenderSignedContract
{
    /**
     * Millimetres per point, for turning a box height into a font size.
     *
     * Fields are stored as fractions of the page and TCPDF measures pages in
     * millimetres, but font sizes are in points. This is the one place the two
     * meet.
     */
    private const MM_PER_POINT = 0.352777778;

    /** Kept legible at the bottom and inside the box at the top. */
    private const MIN_FONT_PT = 6.0;

    private const MAX_FONT_PT = 14.0;

    /**
     * Compose it and hang it on the contract.
     *
     * @throws SigningRefused When the source is gone or has changed under us.
     */
    public function handle(Contract $contract): void
    {
        $contract->loadMissing(['fields', 'signers', 'workspace', 'author']);

        $source = $this->verifiedSource($contract);

        $pdf = new Fpdi;

        /*
         * No header, no footer and no automatic page break.
         *
         * The first two would draw TCPDF's own furniture over somebody's
         * contract. The third is subtler and would be worse: with it on, a
         * field placed near the foot of a page makes TCPDF helpfully start a
         * new one, and the overlay would walk off the end of the document it
         * was supposed to sit on.
         */
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCreator($contract->workspace->name);
        $pdf->SetTitle($contract->title);

        $pageCount = $pdf->setSourceFile($source);

        $answers = $this->answersBySigner($contract);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $measured = $pdf->getTemplateSize($template);

            /*
             * False rather than a size means FPDI could not measure the page.
             * Refused here rather than carried on with, because every box on
             * this page is placed as a fraction of its width and height — a
             * page we cannot measure is one we would stamp signatures onto at
             * coordinates that mean nothing.
             */
            if (! is_array($measured)) {
                throw new SigningRefused('A page of the source document could not be measured.');
            }

            /*
             * Read into the three keys this class actually uses. FPDI documents
             * the return as "array or false" and fills it with numeric aliases
             * beside the named keys, so the shape drawField relies on is
             * established here rather than assumed page after page.
             */
            $size = [
                'width' => (float) $measured['width'],
                'height' => (float) $measured['height'],
                'orientation' => (string) $measured['orientation'],
            ];

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            foreach ($contract->fields->where('page', $page) as $field) {
                $this->drawField($pdf, $contract, $field, $answers, $size);
            }
        }

        $this->drawAuditTrail($pdf, $contract);

        /*
         * Written to a temporary file rather than held in a string.
         *
         * A twenty-page contract with a subsetted font in it is a few megabytes,
         * and handing that to the media library as a string would mean holding
         * two copies in memory while it is written. This runs on a queue beside
         * other jobs.
         */
        $target = (string) tempnam(sys_get_temp_dir(), 'contract-signed-');

        $pdf->Output($target, 'F');

        try {
            $contract->addMedia($target)
                ->usingFileName($this->filename($contract))
                ->usingName($contract->title)
                ->toMediaCollection(Contract::SIGNED);
        } finally {
            // The media library moves the file on success, so this only ever
            // has something to clear up when it did not.
            @unlink($target);
        }
    }

    /**
     * The source file, checked to be the one that was signed.
     *
     * Checked again here although SignContract already did: that was at the
     * moment of signing and this runs later, on a queue, possibly after a
     * retry. Producing a "signed copy" out of a document nobody signed is the
     * one failure this whole feature exists to make impossible, so it is worth
     * asking twice.
     */
    private function verifiedSource(Contract $contract): string
    {
        $media = $contract->source();

        if ($media === null || ! is_file($media->getPath())) {
            throw new SigningRefused('The source document is missing.');
        }

        $path = $media->getPath();
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new SigningRefused('The source document could not be read.');
        }

        if ($contract->source_hash !== null && ! hash_equals($contract->source_hash, $hash)) {
            throw new SigningRefused('The source document has changed since it was signed.');
        }

        return $path;
    }

    /**
     * Every answer, keyed by signer and then by field.
     *
     * Gathered once rather than asked per box, because a twenty-page contract
     * with initials on every page is forty lookups that are all the same query.
     *
     * @return array<string, array<int, ContractFieldValue>>
     */
    private function answersBySigner(Contract $contract): array
    {
        $answers = [];

        foreach ($contract->fieldValues()->get() as $value) {
            $answers[$value->contract_signer_id][$value->contract_field_id] = $value;
        }

        return $answers;
    }

    /**
     * Put one answer on the page.
     *
     * Silently draws nothing where there is no answer, and that is right: a
     * signer who refused leaves their boxes empty, and an optional box somebody
     * skipped is a box that was meant to stay blank. The finished document
     * showing an empty line is the truth about what happened.
     *
     * @param  array<string, array<int, ContractFieldValue>>  $answers
     * @param  array{width: float, height: float, orientation: string}  $size
     */
    private function drawField(
        Fpdi $pdf,
        Contract $contract,
        ContractField $field,
        array $answers,
        array $size,
    ): void {
        $signer = $contract->signers->firstWhere('signing_order', $field->signerIndex());

        if ($signer === null) {
            return;
        }

        $value = $answers[$signer->id][$field->id] ?? null;

        if ($value === null || $value->filled_at === null) {
            return;
        }

        // Fractions become millimetres here and nowhere else.
        $x = $field->x * $size['width'];
        $y = $field->y * $size['height'];
        $width = $field->width * $size['width'];
        $height = $field->height * $size['height'];

        if ($field->type->isDrawn()) {
            $this->drawMark($pdf, $signer, $field->type, $x, $y, $width, $height);

            return;
        }

        if ($field->type === ContractFieldType::Checkbox) {
            $this->drawTick($pdf, $x, $y, $width, $height);

            return;
        }

        $this->drawText($pdf, $field, (string) $value->value, $x, $y, $width, $height);
    }

    /**
     * Somebody's signature or initials, fitted inside the box.
     *
     * Fitted rather than stretched, and that is not a nicety: a signature
     * squashed to the proportions of whatever rectangle the author happened to
     * drag is not the mark that person made. It is centred in what room is left
     * over, which is where anybody would expect to find it.
     */
    private function drawMark(
        Fpdi $pdf,
        ContractSigner $signer,
        ContractFieldType $type,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        $media = $signer->mark($type);

        if ($media === null || ! is_file($media->getPath())) {
            return;
        }

        $pixels = @getimagesize($media->getPath());

        if ($pixels === false || $pixels[0] === 0 || $pixels[1] === 0) {
            return;
        }

        $scale = min($width / $pixels[0], $height / $pixels[1]);

        $drawnWidth = $pixels[0] * $scale;
        $drawnHeight = $pixels[1] * $scale;

        $pdf->Image(
            $media->getPath(),
            $x + ($width - $drawnWidth) / 2,
            $y + ($height - $drawnHeight) / 2,
            $drawnWidth,
            $drawnHeight,
            'PNG',
            '',
            '',
            // Resampled, so a signature drawn at three times the size it is
            // printed at does not go into the file at three times the weight.
            true,
        );
    }

    /**
     * A tick in a box that was ticked.
     *
     * Two lines rather than a character, because a checkmark glyph depends on
     * the font having one — and the one font here is chosen for covering every
     * name somebody might type, not for its dingbats.
     */
    private function drawTick(Fpdi $pdf, float $x, float $y, float $width, float $height): void
    {
        $pdf->SetLineWidth(min($width, $height) * 0.12);
        $pdf->SetDrawColor(17, 24, 39);

        $pdf->Line($x + $width * 0.2, $y + $height * 0.5, $x + $width * 0.42, $y + $height * 0.78);
        $pdf->Line($x + $width * 0.42, $y + $height * 0.78, $x + $width * 0.82, $y + $height * 0.2);
    }

    /**
     * What somebody typed, in the box they typed it in.
     *
     * The font size is worked out from the height of the box rather than fixed,
     * because the boxes are drawn by hand over somebody else's document: a
     * signature line on a dense contract may be three millimetres tall and a
     * name field on a cover page fifteen. One fixed size would overflow the
     * first and look lost in the second.
     */
    private function drawText(
        Fpdi $pdf,
        ContractField $field,
        string $value,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        if ($value === '') {
            return;
        }

        $lineHeight = $field->type === ContractFieldType::Multiline
            ? min($height, 5.0)
            : $height;

        $points = max(
            self::MIN_FONT_PT,
            min(self::MAX_FONT_PT, ($lineHeight * 0.62) / self::MM_PER_POINT),
        );

        /*
         * dejavusans rather than one of the core fonts, and it costs about
         * 200 kB of subsetted glyphs to say so.
         *
         * The core fonts are latin-1. What goes in these boxes is people's
         * names and addresses, and a contract that turned Grzegorz Brzęczyszkiewicz
         * into mojibake would be worse than one that is slightly larger.
         */
        $pdf->SetFont('dejavusans', '', $points);
        $pdf->SetTextColor(17, 24, 39);

        if ($field->type === ContractFieldType::Multiline) {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($width, $lineHeight, $value, 0, 'L', false, 1, $x, $y, true, 0, false, true, $height, 'T');

            return;
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($width, $height, $this->display($field, $value), 0, 0, 'L', false, '', 1);
    }

    /** A stored value in the shape a person reads it on paper. */
    private function display(ContractField $field, string $value): string
    {
        if ($field->type !== ContractFieldType::Date) {
            return $value;
        }

        // Stored as Y-m-d — see ContractFieldType::rules. Printed the way a
        // Dutch contract writes a date.
        $date = date_create($value);

        return $date === false ? $value : $date->format('d-m-Y');
    }

    /**
     * The page that makes the rest of it worth anything.
     *
     * Always added, including to a contract everybody refused: the record of who
     * was asked and what they said is exactly as much of a document as a set of
     * signatures is, and it is the thing somebody will be looking for.
     */
    private function drawAuditTrail(Fpdi $pdf, Contract $contract): void
    {
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage('P', 'A4');

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->Cell(0, 10, __('contracts.audit.heading'), 0, 1);

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(0, 5, __('contracts.audit.intro', [
            'title' => $contract->title,
            'workspace' => $contract->workspace->name,
        ]), 0, 'L');

        $pdf->Ln(3);

        $this->auditLine($pdf, __('contracts.audit.document'), $contract->title);
        $this->auditLine($pdf, __('contracts.audit.sent_by'), $contract->author->name ?? $contract->workspace->name);
        $this->auditLine($pdf, __('contracts.audit.completed_at'), $contract->completed_at?->format('d-m-Y H:i') ?? '—');

        /*
         * The hash, in a monospaced face and broken over two lines rather than
         * shrunk to fit.
         *
         * This is the one string on the page somebody may one day have to
         * compare character by character against a file they were handed, and a
         * proportional font makes that job miserable — an l and a 1 have to be
         * different shapes.
         */
        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(0, 5, __('contracts.audit.hash'), 0, 1);
        $pdf->SetFont('dejavusansmono', '', 8);
        $pdf->MultiCell(0, 4, (string) $contract->source_hash, 0, 'L');

        $pdf->Ln(4);

        foreach ($contract->signers as $signer) {
            $this->drawSigner($pdf, $signer, $contract);
        }
    }

    /** One label-and-value line on the audit page. */
    private function auditLine(Fpdi $pdf, string $label, string $value): void
    {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(40, 5, $label, 0, 0);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->MultiCell(0, 5, $value, 0, 'L');
    }

    /** What one person did, and everything recorded about their doing it. */
    private function drawSigner(Fpdi $pdf, ContractSigner $signer, Contract $contract): void
    {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 6, $signer->name.' — '.$signer->email, 0, 1);

        $pdf->SetFont('dejavusans', '', 9);

        $this->auditLine($pdf, __('contracts.audit.opened_at'),
            $signer->opened_at?->format('d-m-Y H:i') ?? __('contracts.audit.never'));

        if ($signer->hasSigned()) {
            $this->auditLine($pdf, __('contracts.audit.signed_at'), $signer->signed_at->format('d-m-Y H:i'));
            $this->auditLine($pdf, __('contracts.audit.ip'), $signer->ip_address ?? '—');
            $this->auditLine($pdf, __('contracts.audit.method'),
                $signer->signature_method?->statement() ?? '—');

            if ($signer->signature_text !== null) {
                $this->auditLine($pdf, __('contracts.audit.typed_as'), $signer->signature_text);
            }

            /*
             * The hash this person signed under, and it is printed even when it
             * matches — because "these agree" is only reassuring if both were
             * shown. What it is compared against is the one at the top of the
             * page: if the two ever differ, that difference is the single most
             * important thing on the document.
             */
            $matches = $signer->signed_document_hash !== null
                && $contract->source_hash !== null
                && hash_equals($contract->source_hash, $signer->signed_document_hash);

            $this->auditLine($pdf, __('contracts.audit.signed_hash'), $matches
                ? __('contracts.audit.hash_matches')
                : (string) $signer->signed_document_hash);
        } elseif ($signer->hasDeclined()) {
            $this->auditLine($pdf, __('contracts.audit.declined_at'), $signer->declined_at->format('d-m-Y H:i'));

            if ($signer->decline_reason !== null) {
                $this->auditLine($pdf, __('contracts.audit.reason'), $signer->decline_reason);
            }
        } else {
            $this->auditLine($pdf, __('contracts.audit.outcome'), __('contracts.audit.no_answer'));
        }

        $pdf->Ln(3);
    }

    /**
     * What the file is called when somebody downloads it.
     *
     * Built from the title rather than kept from the upload, because this is
     * not that document any more — and "Huurovereenkomst 2026 (ondertekend).pdf"
     * in a downloads folder beside the original is the difference between two
     * files somebody can tell apart and two they cannot.
     */
    private function filename(Contract $contract): string
    {
        $safe = preg_replace('/[^\p{L}\p{N} \-_.]+/u', '', $contract->title) ?? 'contract';

        return trim($safe).' '.__('contracts.audit.filename_suffix').'.pdf';
    }
}
