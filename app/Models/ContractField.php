<?php

namespace App\Models;

use App\Enums\ContractFieldType;
use Database\Factories\ContractFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One box drawn over a page of the contract.
 *
 * Everything here is geometry plus a question. The geometry is relative to the
 * page — see the migration for why — and the question is the type and the
 * label: what goes in the box, and what to call it where somebody is asked to
 * fill it in.
 *
 * No timestamps: a field is part of the document's design rather than an event.
 * When it was drawn is of no interest to anybody, and once the invitations have
 * gone out the design does not change — see ContractPolicy.
 *
 * @property int $id
 * @property string $contract_id
 * @property int $page
 * @property float $x
 * @property float $y
 * @property float $width
 * @property float $height
 * @property ContractFieldType $type
 * @property string $label
 * @property bool $is_required
 * @property int $position
 * @property int|null $signer_index
 */
#[Fillable(['contract_id', 'page', 'x', 'y', 'width', 'height', 'type', 'label', 'is_required', 'position', 'signer_index'])]
class ContractField extends Model
{
    /** @use HasFactory<ContractFieldFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'type' => ContractFieldType::class,
            'is_required' => 'boolean',
            'position' => 'integer',
            'signer_index' => 'integer',
        ];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * What everybody put in this box — one row per signer who was asked.
     *
     * @return HasMany<ContractFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ContractFieldValue::class);
    }

    /**
     * Which signer this box is for, counting from zero.
     *
     * Null on the column means the first one, so the question "is this mine"
     * has a single answer rather than two shapes to compare against — see the
     * migration for why the column is allowed to be null at all.
     */
    public function signerIndex(): int
    {
        return $this->signer_index ?? 0;
    }

    public function belongsToSigner(ContractSigner $signer): bool
    {
        return $this->signerIndex() === $signer->signing_order;
    }

    /**
     * Whether this box has been dealt with by the person it was for.
     *
     * The one place that knows a drawn field is answered by filled_at rather
     * than by a value: a signature's image hangs on the signer, so the value row
     * carries nothing but the fact that it happened. A tickbox is the mirror
     * case — an unticked box is stored as null, so filled_at is what tells
     * "niet aangevinkt" apart from "niet langs geweest".
     */
    public function isSatisfiedBy(?ContractFieldValue $value): bool
    {
        if (! $this->is_required) {
            return true;
        }

        if ($value === null) {
            return false;
        }

        if ($this->type->isDrawn()) {
            return $value->filled_at !== null;
        }

        /*
         * A required tickbox means "vink dit aan", not "beslis hierover".
         *
         * The rule that reads oddly until you say it out loud: a contract that
         * asks somebody to confirm they have read the terms is not satisfied by
         * them confirming they have not. Where a form's yes/no takes either
         * answer — see FormFieldType — a contract's required tickbox takes one.
         */
        return $value->value !== null && $value->value !== '';
    }
}
