<?php

namespace App\Actions\Contracts;

use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keep what somebody has typed so far.
 *
 * The whole reason this exists is the tab that gets closed. A contract of any
 * length is not filled in in one sitting — somebody goes to look up a
 * registration number, their laptop sleeps, they come back an hour later — and
 * a page that lost their work would be a page they do not come back to.
 *
 * A draft is deliberately not an answer. Nothing is required of it, and
 * filled_at only goes on where there is something to record, so "leeg gelaten"
 * and "niet langs geweest" stay apart right up until the moment of signing —
 * which is where required is finally asked.
 */
class SaveSignerDraft
{
    /**
     * The validation this signer's own boxes impose.
     *
     * Built from the fields rather than from a fixed list, because the rules
     * are the author's: a date box wants a date, a text box has a length. Only
     * this signer's boxes appear, so a payload addressed to somebody else's
     * field has nothing to validate against and is dropped below.
     *
     * @return array<string, mixed>
     */
    public function rulesFor(ContractSigner $signer, bool $draft = true): array
    {
        $rules = ['values' => ['present', 'array']];

        foreach ($this->fieldsFor($signer) as $field) {
            $rules['values.'.$field->id] = $field->type->rules($field, $draft);
        }

        return $rules;
    }

    /**
     * Write what came in, leaving out what was not asked.
     *
     * @param  array<int|string, mixed>  $values  Keyed by field id.
     * @param  bool  $draft  Whether this is an intermediate save. A draft
     *                       records what was typed; anything else also stamps
     *                       filled_at, which is what turns a value into an answer.
     */
    public function handle(ContractSigner $signer, array $values, bool $draft = true): void
    {
        $fields = $this->fieldsFor($signer);

        DB::transaction(function () use ($signer, $fields, $values, $draft): void {
            foreach ($fields as $field) {
                /*
                 * A drawn field is skipped whatever arrived for it. Its answer
                 * is an image on the signer, and a text value here would be a
                 * signature somebody typed into the network tab.
                 */
                if ($field->type->isDrawn()) {
                    continue;
                }

                if (! array_key_exists($field->id, $values)) {
                    continue;
                }

                $value = $field->type->normalise($values[$field->id]);

                /*
                 * updateOrCreate against the unique index on (field, signer).
                 *
                 * That index is what makes this safe to call as often as
                 * somebody types: a dropped connection retrying a save updates
                 * the row it wrote rather than laying a second one beside it.
                 */
                ContractFieldValue::updateOrCreate(
                    [
                        'contract_field_id' => $field->id,
                        'contract_signer_id' => $signer->id,
                    ],
                    [
                        'value' => $value,

                        /*
                         * A draft leaves this alone rather than clearing it: a
                         * box that was answered and is being edited again is
                         * still answered, and blanking the stamp mid-sentence
                         * would make a finished contract look unfinished for as
                         * long as somebody's cursor was in it.
                         */
                        ...$draft ? [] : ['filled_at' => now()],
                    ],
                );
            }
        });
    }

    /**
     * The boxes this person was asked to fill in.
     *
     * @return Collection<int, ContractField>
     */
    private function fieldsFor(ContractSigner $signer): Collection
    {
        return $signer->contract->fields->filter(
            fn (ContractField $field): bool => $field->belongsToSigner($signer)
        );
    }
}
