<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Database\Factories\FormAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody wrote in one box.
 *
 * Carries its own question, key and type — see the migration for why. The
 * field it points at is for grouping and for the builder; everything a reader
 * needs is on the row itself.
 *
 * @property int $id
 * @property string $form_submission_id
 * @property int|null $form_field_id
 * @property string $field_key
 * @property string $question
 * @property FormFieldType $type
 * @property mixed $value
 * @property int $position
 * @property-read FormField|null $field Gone once the question was deleted,
 *     which is exactly the case the copies above exist for.
 */
#[Fillable(['form_submission_id', 'form_field_id', 'field_key', 'question', 'type', 'value', 'position'])]
class FormAnswer extends Model
{
    /** @use HasFactory<FormAnswerFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FormFieldType::class,
            'value' => 'json',
        ];
    }

    /** @return BelongsTo<FormSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** The answer as a person reads it. */
    public function display(): string
    {
        return $this->type->display($this->value);
    }
}
