<?php

namespace App\Actions\Contracts;

use App\Enums\ContractFieldType;
use App\Models\Contract;
use App\Models\ContractField;
use Illuminate\Support\Facades\DB;

/**
 * Put the editor's boxes where the contract's boxes used to be.
 *
 * Written as a sync rather than as create/move/delete endpoints per box,
 * because that is what the screen is: one page somebody drags things about on
 * and then saves. The same shape SaveFormFields has, and it is safe here for a
 * reason that is worth stating plainly rather than assumed.
 *
 * A field that has been filled in must never be quietly replaced by a
 * lookalike. That is not enforced here — it is enforced one level up, by
 * ContractPolicy::update, which stops allowing this the moment anybody has
 * signed. So by the time this runs, no answer exists that could be orphaned,
 * and a field that vanishes from the payload can simply go.
 *
 * What that leaves is the ordinary case: an author moving a signature box down
 * two centimetres before sending the thing out.
 */
class SaveContractFields
{
    /**
     * @param  list<array{id?: int|null, page: int, x: float, y: float, width: float, height: float, type: string, label: string, is_required?: bool, signer_index?: int|null}>  $fields
     */
    public function handle(Contract $contract, array $fields): void
    {
        DB::transaction(function () use ($contract, $fields): void {
            $kept = [];

            foreach ($fields as $position => $field) {
                $attributes = [
                    'page' => (int) $field['page'],

                    /*
                     * Clamped here as well as in the browser, and not by
                     * mistake: the editor keeps a box on the page because that
                     * is what the person meant, this refuses one off the page
                     * because a coordinate outside 0..1 would put a signature
                     * somewhere the renderer has no pixels for.
                     */
                    'x' => $this->fraction($field['x']),
                    'y' => $this->fraction($field['y']),
                    'width' => $this->fraction($field['width']),
                    'height' => $this->fraction($field['height']),

                    'type' => ContractFieldType::from($field['type']),
                    'label' => trim($field['label']),
                    'is_required' => (bool) ($field['is_required'] ?? true),
                    'position' => $position,

                    /*
                     * Null and zero both mean "de eerste ondertekenaar" — see
                     * ContractField::signerIndex — so the one that arrives is
                     * stored as it came rather than normalised. Normalising
                     * would rewrite the author's rows on every save without
                     * changing what anybody sees.
                     */
                    'signer_index' => $field['signer_index'] ?? null,
                ];

                $existing = isset($field['id'])
                    ? $contract->fields()->whereKey($field['id'])->first()
                    : null;

                if ($existing !== null) {
                    $existing->update($attributes);
                    $kept[] = $existing->id;

                    continue;
                }

                $kept[] = ContractField::create([
                    'contract_id' => $contract->id,
                    ...$attributes,
                ])->id;
            }

            $contract->fields()->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
        });
    }

    /**
     * A coordinate, as a fraction of the page.
     *
     * Rounded to what the column holds rather than left to the database to
     * truncate, so that what comes back out on the next load is what the editor
     * put in — otherwise a box would shift by a millionth on every save round
     * trip and the page would report unsaved changes it does not have.
     */
    private function fraction(mixed $value): float
    {
        return round(min(1.0, max(0.0, (float) $value)), 8);
    }
}
