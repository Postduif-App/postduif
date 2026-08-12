<?php

namespace App\Enums;

use App\Models\ContractField;

/**
 * What a box drawn over the PDF asks for.
 *
 * A closed list, like FormFieldType and for the same reason: every case here is
 * drawn in the editor, rendered again on the public page, validated on the way
 * in and finally painted onto the finished PDF. A type a contract could invent
 * would be a box nobody could fill or print.
 *
 * The split that runs through the whole feature is between the four types
 * somebody types into and the two they draw into. A typed value is a string in
 * a column; a drawn one is a PNG on the private disk, hung on the signer rather
 * than on the value — see Contract's model docs. Everything that has to tell
 * those apart asks isDrawn().
 */
enum ContractFieldType: string
{
    case Text = 'text';
    case Multiline = 'multiline';
    case Date = 'date';
    case Checkbox = 'checkbox';
    case Signature = 'signature';
    case Initials = 'initials';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('contracts.field-types.text'),
            self::Multiline => __('contracts.field-types.multiline'),
            self::Date => __('contracts.field-types.date'),
            self::Checkbox => __('contracts.field-types.checkbox'),
            self::Signature => __('contracts.field-types.signature'),
            self::Initials => __('contracts.field-types.initials'),
        };
    }

    /**
     * Whether filling this in means drawing rather than typing.
     *
     * The one question that decides where the answer lives: a drawn field's
     * value is an image on the private disk and its `value` column stays null,
     * a typed field is the other way round. Asked by the public page, by the
     * validator and by the renderer, so it is stated once here.
     */
    public function isDrawn(): bool
    {
        return $this === self::Signature || $this === self::Initials;
    }

    /**
     * Whether a signer putting this box down counts as having signed.
     *
     * Only the signature does. Initials are the same mechanism at a smaller
     * size — see the epic — and a contract initialled on every page but never
     * signed at the end is not a signed contract.
     */
    public function isSignature(): bool
    {
        return $this === self::Signature;
    }

    /**
     * How big the box starts out when it is dropped on the page, as a fraction
     * of the page width and height.
     *
     * Relative for the reason every coordinate here is: the editor renders at
     * whatever scale the screen allows, so a size in pixels would mean a
     * different box on a laptop than on a monitor. See the migration.
     *
     * A starting point, not a constraint — the editor lets every box be dragged
     * to any size. What these buy is that a freshly dropped signature box is
     * already roughly signature-shaped rather than a square somebody has to
     * correct every time.
     *
     * @return array{width: float, height: float}
     */
    public function defaultSize(): array
    {
        return match ($this) {
            self::Text, self::Date => ['width' => 0.28, 'height' => 0.028],
            self::Multiline => ['width' => 0.42, 'height' => 0.10],
            self::Checkbox => ['width' => 0.025, 'height' => 0.018],
            self::Signature => ['width' => 0.26, 'height' => 0.08],
            self::Initials => ['width' => 0.08, 'height' => 0.05],
        };
    }

    /**
     * The validation a typed answer to this box has to survive.
     *
     * Built from the field rather than from the type alone, because "verplicht"
     * is a fact about the box somebody drew, not about the kind of box it is.
     * Drawn fields are absent from the match on purpose: their answer arrives as
     * an uploaded image and is judged as one, not as a string.
     *
     * @return array<int, mixed>
     */
    public function rules(ContractField $field): array
    {
        $presence = $field->is_required ? 'required' : 'nullable';

        return match ($this) {
            self::Text => [$presence, 'string', 'max:500'],
            self::Multiline => [$presence, 'string', 'max:5000'],

            /*
             * A date on a contract is a date on a contract — stored as Y-m-d and
             * formatted where it is shown. Accepting free text here would put
             * "volgende week dinsdag" in a field the finished PDF has to print.
             */
            self::Date => [$presence, 'date_format:Y-m-d'],

            /*
             * An unticked box arrives as false, never as missing, so "required"
             * would refuse a perfectly good "nee" — the same trap FormFieldType
             * spells out for its yes/no. A required tickbox on a contract means
             * something else entirely (see ContractField::isSatisfiedBy), and
             * that is enforced there rather than by a presence rule.
             */
            self::Checkbox => ['nullable', 'boolean'],

            self::Signature, self::Initials => ['prohibited'],
        };
    }

    /**
     * Whatever came out of the page, in the shape the value column holds.
     *
     * Everything ends up a string or null, because the column is text: this is
     * one document's worth of answers, not a dataset anybody queries. A tickbox
     * is the one that needs saying out loud — '1' and '' rather than true and
     * false, so that "leeg" means the same thing for every type.
     */
    public function normalise(mixed $value): ?string
    {
        if ($this->isDrawn()) {
            return null;
        }

        if ($this === self::Checkbox) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : null;
        }

        return $value === '' || $value === null ? null : (string) $value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
