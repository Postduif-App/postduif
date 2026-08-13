<?php

namespace App\Actions\Contracts;

use App\Enums\ContractFieldType;
use App\Enums\SignatureMethod;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Put down the mark that stands for somebody's name.
 *
 * One mark per kind, reused across every box of that kind. A contract that asks
 * for initials at the foot of nine pages must not ask anybody to draw nine
 * times — and nine slightly different drawings would be worse than one, because
 * the differences would be noise that looks like meaning.
 *
 * What that costs is that the boxes are not independent: setting a signature
 * fills every signature box at once, and clearing it empties them all. That is
 * the right trade for the same reason — they were never nine decisions.
 */
class StoreSignature
{
    /**
     * The image, however it was made.
     *
     * The method is recorded rather than inferred, because a PNG of a typed
     * name and a PNG of a drawn one are both just pixels afterwards. See
     * SignatureMethod.
     *
     * @param  string|null  $typed  The name as it was typed, for the typed
     *                              method and nothing else. Kept beside the picture because a picture
     *                              of text is not text.
     */
    public function handle(
        ContractSigner $signer,
        ContractFieldType $type,
        UploadedFile $image,
        SignatureMethod $method,
        ?string $typed = null,
    ): void {
        DB::transaction(function () use ($signer, $type, $image, $method, $typed): void {
            /*
             * singleFile on the collection, so this replaces rather than piles
             * up — drawing again means "dat was niet goed", not "ik heb er nu
             * twee". See ContractSigner::registerMediaCollections.
             */
            $signer->addMedia($image->getRealPath())
                ->usingFileName($type->value.'.png')
                ->toMediaCollection($signer->collectionFor($type));

            $signer->forceFill([
                'signature_method' => $method,
                'signature_text' => $method === SignatureMethod::Typed ? $typed : null,
            ])->save();

            $this->stamp($signer, $type);
        });
    }

    /**
     * Take the mark away again.
     *
     * Its own method rather than a null image, because the two are different
     * acts: replacing a signature leaves a contract just as signed as it was,
     * and clearing one takes every box of that kind back to empty. Which is
     * exactly what somebody who drew a wobbly line with a mouse wants.
     */
    public function clear(ContractSigner $signer, ContractFieldType $type): void
    {
        DB::transaction(function () use ($signer, $type): void {
            $signer->clearMediaCollection($signer->collectionFor($type));

            ContractFieldValue::query()
                ->whereIn('contract_field_id', $this->fieldIds($signer, $type))
                ->where('contract_signer_id', $signer->id)
                ->update(['filled_at' => null]);
        });
    }

    /**
     * Mark every box of this kind as dealt with.
     *
     * A drawn field's value row carries nothing but filled_at — the image hangs
     * on the signer, not on the answer — so this stamp is the whole of what
     * makes a signature box count as answered. Without it, a fully signed
     * contract would report itself as incomplete.
     */
    private function stamp(ContractSigner $signer, ContractFieldType $type): void
    {
        foreach ($this->fieldIds($signer, $type) as $fieldId) {
            ContractFieldValue::updateOrCreate(
                [
                    'contract_field_id' => $fieldId,
                    'contract_signer_id' => $signer->id,
                ],
                ['filled_at' => now()],
            );
        }
    }

    /**
     * This signer's own boxes of this kind.
     *
     * Filtered by signer as well as by type, and that is not belt and braces: a
     * contract signed by two people has both their signature boxes on it, and
     * stamping the other person's would report them as having signed.
     *
     * @return list<int>
     */
    private function fieldIds(ContractSigner $signer, ContractFieldType $type): array
    {
        $signer->contract->loadMissing('fields');

        return array_values(
            $signer->contract->fields
                ->filter(fn (ContractField $field): bool => $field->type === $type
                    && $field->belongsToSigner($signer))
                ->map(fn (ContractField $field): int => $field->id)
                ->all()
        );
    }
}
