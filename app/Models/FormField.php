<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Database\Factories\FormFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question on a form.
 *
 * @property int $id
 * @property string $form_id
 * @property string $key
 * @property FormFieldType $type
 * @property string $label
 * @property string|null $hint
 * @property bool $required
 * @property list<string> $options
 * @property int $position
 */
#[Fillable(['form_id', 'key', 'type', 'label', 'hint', 'required', 'options', 'position'])]
class FormField extends Model
{
    /** @use HasFactory<FormFieldFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $attributes = [
        'options' => '[]',
        'required' => true,
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FormFieldType::class,
            'required' => 'boolean',
            'options' => 'array',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
