<?php

namespace App\Enums;

use App\Models\FormField;

/**
 * What kind of answer a field asks for.
 *
 * A closed list, like WorkspaceAbility and for the same reason: every case here
 * has to be validated on the way in, rendered in three places, and turned back
 * into a sentence for the DM. A type a form could invent would be a field
 * nobody could read.
 *
 * The knowledge sits here rather than spread over the request, the React page
 * and the bot message, so that adding a type is one file plus one arm in the
 * builder's field picker.
 */
enum FormFieldType: string
{
    case ShortText = 'short-text';
    case LongText = 'long-text';
    case Choice = 'choice';
    case MultipleChoice = 'multiple-choice';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    public function label(): string
    {
        return match ($this) {
            self::ShortText => __('forms.types.short-text'),
            self::LongText => __('forms.types.long-text'),
            self::Choice => __('forms.types.choice'),
            self::MultipleChoice => __('forms.types.multiple-choice'),
            self::Number => __('forms.types.number'),
            self::Date => __('forms.types.date'),
            self::Boolean => __('forms.types.boolean'),
        };
    }

    /**
     * Whether the field carries a list of choices to pick from.
     *
     * Asked by the builder, which only shows the options editor for these two,
     * and by the request, which refuses options on the rest rather than
     * quietly storing a list nobody will ever see.
     */
    public function takesOptions(): bool
    {
        return $this === self::Choice || $this === self::MultipleChoice;
    }

    /** Whether an answer is a list rather than a single value. */
    public function isMultiple(): bool
    {
        return $this === self::MultipleChoice;
    }

    /**
     * The validation an answer to this field has to survive.
     *
     * Built from the field rather than from the type alone, because "required"
     * and "one of these options" are facts about the field somebody made, not
     * about the kind of field it is.
     *
     * @return array<int, mixed>
     */
    public function rules(FormField $field): array
    {
        $presence = $field->required ? 'required' : 'nullable';

        return match ($this) {
            self::ShortText => [$presence, 'string', 'max:500'],
            self::LongText => [$presence, 'string', 'max:5000'],
            self::Number => [$presence, 'numeric'],
            self::Date => [$presence, 'date'],

            /*
             * A tickbox that is not ticked arrives as false, never as missing,
             * so "required" would refuse a perfectly good "nee". A required
             * yes/no means "answer it", and both answers are answers — which is
             * why this one is always nullable and cast below.
             */
            self::Boolean => ['nullable', 'boolean'],

            self::Choice => [$presence, 'string', 'in:'.implode(',', $field->options)],
            self::MultipleChoice => [$presence, 'array'],
        };
    }

    /**
     * The rule for each entry of a list answer, or null when the answer is not
     * a list.
     *
     * @return array<int, mixed>|null
     */
    public function entryRules(FormField $field): ?array
    {
        if (! $this->isMultiple()) {
            return null;
        }

        return ['string', 'in:'.implode(',', $field->options)];
    }

    /**
     * Whatever came out of the form, in the shape the answer is stored in.
     *
     * Normalising here rather than in the action keeps "what a date looks like
     * in the database" next to "what a date is validated as", which is the pair
     * that has to agree.
     */
    public function normalise(mixed $value): mixed
    {
        return match ($this) {
            self::Boolean => (bool) $value,
            self::Number => $value === null || $value === '' ? null : (float) $value,
            self::MultipleChoice => array_values(array_filter(
                is_array($value) ? $value : [],
                fn (mixed $entry): bool => is_string($entry) && $entry !== '',
            )),
            default => $value === '' ? null : $value,
        };
    }

    /**
     * The answer as a person reads it — in the DM, on the answers screen, in
     * the CSV.
     *
     * One method for all three so a holiday request cannot say "1" in the
     * message and "ja" on the screen.
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === []) {
            return __('forms.answers.empty');
        }

        return match ($this) {
            self::Boolean => $value ? __('forms.answers.yes') : __('forms.answers.no'),
            self::MultipleChoice => implode(', ', array_map(strval(...), (array) $value)),
            self::Number => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ','),
            default => (string) $value,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
