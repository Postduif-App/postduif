<?php

namespace App\Enums;

/**
 * How somebody produced the mark that stands for their name.
 *
 * Two, and the distinction is recorded rather than inferred because it is a
 * fact about the signing that the audit trail has to be able to state. Both are
 * a simple electronic signature under eIDAS and neither is worth more than the
 * other legally — but "hij heeft getekend" and "hij heeft zijn naam ingetypt"
 * are different accounts of what happened, and the person reading the trail
 * later is entitled to the true one.
 *
 * What ends up stored is a PNG either way. That is deliberate: the renderer
 * that composes the signed copy pastes an image and has no second code path,
 * and the image is what the signer actually saw and approved before pressing
 * the button. This column is what keeps the difference from being lost in the
 * pixels.
 */
enum SignatureMethod: string
{
    case Drawn = 'drawn';
    case Typed = 'typed';

    public function label(): string
    {
        return match ($this) {
            self::Drawn => __('enums.signature-method.label.Drawn'),
            self::Typed => __('enums.signature-method.label.Typed'),
        };
    }

    /**
     * How the audit trail on the finished PDF phrases it.
     *
     * Beside the label because the two are read in different places: a label
     * sits next to a choice somebody is making, this sits in a record somebody
     * is checking months later and has to stand on its own.
     */
    public function statement(): string
    {
        return match ($this) {
            self::Drawn => __('enums.signature-method.statement.Drawn'),
            self::Typed => __('enums.signature-method.statement.Typed'),
        };
    }
}
