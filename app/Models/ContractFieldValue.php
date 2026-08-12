<?php

namespace App\Models;

use Database\Factories\ContractFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one person put in one box.
 *
 * Deliberately thin. The value is text or nothing, and for a signature it is
 * nothing at all — the drawing hangs on the signer, and what this row then
 * carries is filled_at: the fact that the box was dealt with. That is the whole
 * reason filled_at exists beside the value rather than being inferred from it,
 * because "leeg gelaten" and "niet langs geweest" are different answers and an
 * unticked tickbox stores as null either way.
 *
 * No timestamps beyond that one. A draft is saved over and over as somebody
 * types — see the unique index in the migration — so created_at would record
 * when they started and updated_at would drift with every keystroke. Neither is
 * the moment that matters; the moment that matters is on the signer.
 *
 * @property int $id
 * @property int $contract_field_id
 * @property string $contract_signer_id
 * @property string|null $value
 * @property Carbon|null $filled_at
 */
#[Fillable(['contract_field_id', 'contract_signer_id', 'value', 'filled_at'])]
class ContractFieldValue extends Model
{
    /** @use HasFactory<ContractFieldValueFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ContractField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(ContractField::class, 'contract_field_id');
    }

    /** @return BelongsTo<ContractSigner, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(ContractSigner::class, 'contract_signer_id');
    }
}
