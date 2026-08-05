<?php

namespace App\Workflows;

use App\Enums\WorkflowFieldType;

/**
 * One thing a trigger or an action needs to be told.
 *
 * This is the single description of a field: the builder draws its control from
 * it, the request validates against it, and the runner reads the value it
 * produced. Three readers of one declaration rather than three declarations
 * that have to agree — which they would not, and the first anybody would hear
 * of it is a run that failed.
 */
final class WorkflowField
{
    /**
     * @param  string  $key  Where the value lands in the step's configuration.
     * @param  array<string, string>  $options  For a Choice: value => what it reads as.
     */
    public function __construct(
        public readonly string $key,
        public readonly WorkflowFieldType $type,
        public readonly string $label,
        public readonly ?string $hint = null,
        public readonly bool $required = true,
        public readonly array $options = [],
    ) {}

    public static function text(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Text, $label, $hint, $required);
    }

    public static function longText(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::LongText, $label, $hint, $required);
    }

    public static function channel(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Channel, $label, $hint, $required);
    }

    public static function member(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Member, $label, $hint, $required);
    }

    public static function form(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Form, $label, $hint, $required);
    }

    public static function emoji(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Emoji, $label, $hint, $required);
    }

    public static function number(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Number, $label, $hint, $required);
    }

    public static function words(string $key, string $label, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Words, $label, $hint, $required);
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function choice(string $key, string $label, array $options, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Choice, $label, $hint, $required, $options);
    }

    /**
     * Whether a value for this field may hold {{ ... }}.
     *
     * Asked of the type rather than settable per field, so there is one answer
     * to "can a variable go here" instead of one per declaration — the sort of
     * thing that ends up true in the field somebody wrote last.
     */
    public function acceptsVariables(): bool
    {
        return $this->type->acceptsVariables();
    }

    /**
     * What the builder is handed.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label,
            'hint' => $this->hint,
            'required' => $this->required,
            'acceptsVariables' => $this->acceptsVariables(),
            'options' => $this->options,
        ];
    }
}
