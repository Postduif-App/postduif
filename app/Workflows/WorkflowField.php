<?php

namespace App\Workflows;

use App\Enums\WorkflowFieldType;
use App\Enums\WorkflowRecordType;

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
     * @param  array<int|string, string>  $options  For a Choice: value => what it reads as.
     */
    public function __construct(
        public readonly string $key,
        public readonly WorkflowFieldType $type,
        public readonly string $label,
        public readonly ?string $hint = null,
        public readonly bool $required = true,
        public readonly array $options = [],
        public readonly ?WorkflowRecordType $record = null,
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
     * The keys are int|string rather than string because PHP will not hold a
     * numeric one as anything else: the weekday field is keyed '1'..'7' and
     * arrives here as ints whatever it was written as.
     *
     * @param  array<int|string, string>  $options
     */
    public static function choice(string $key, string $label, array $options, ?string $hint = null, bool $required = true): self
    {
        return new self($key, WorkflowFieldType::Choice, $label, $hint, $required, $options);
    }

    /**
     * Something this workspace has: a ticket, and in time a contract.
     *
     * Optional by default, and that default is the interesting half of the
     * field. An empty box does not mean "unfinished" here — it means the record
     * the trigger was about, which is what almost every workflow acting on a
     * record means. Declaring one of these required is saying that a step is
     * only ever about a record somebody named in advance, which is the rarer
     * case and worth writing out.
     */
    public static function record(string $key, WorkflowRecordType $record, string $label, ?string $hint = null, bool $required = false): self
    {
        return new self($key, WorkflowFieldType::Record, $label, $hint, $required, record: $record);
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
            // Which kind of record the picker should offer. Null for every
            // other type, and the builder draws its control from that.
            'record' => $this->record?->value,
        ];
    }
}
