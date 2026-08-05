<?php

namespace App\Rules;

use App\Models\CustomEmoji;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What may be left on a message, or on a notice on the prikbord.
 *
 * One class rather than the same lines in two form requests. Those carried the
 * rule word for word, with a comment on each saying so — which held right up
 * until custom emoji made it a query, at which point "word for word" would have
 * meant two copies of a workspace lookup.
 */
class ReactionEmoji implements ValidationRule
{
    /** The workspace whose own emoji count as known here. */
    public function __construct(private readonly int $workspaceId) {}

    /**
     * Either a symbol, or the name of a picture this workspace uploaded.
     *
     * Two shapes rather than one pattern, because they are two different
     * promises. A unicode emoji is judged by what it is *not*: no letters, no
     * digits, no whitespace — which keeps "lgtm" out of the reaction row
     * without pinning the column to a fixed list the picker would outgrow. A
     * custom emoji is nothing but letters, so the only honest question about
     * one is whether it exists here, and that is a query.
     *
     * Scoped to the workspace. Without that a ":shipit:" from somewhere else
     * would be stored and then draw as bare text for everybody in the room,
     * because the picture behind it is not theirs to fetch.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $emoji = is_string($value) ? $value : '';

        $name = CustomEmoji::nameFromShortcode($emoji);

        if ($name === null) {
            if (preg_match('/[\s\w]/u', $emoji) === 1) {
                $fail(__('requests.reaction.emoji_only'));
            }

            return;
        }

        $known = CustomEmoji::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('name', $name)
            ->exists();

        if (! $known) {
            $fail(__('requests.reaction.unknown_emoji'));
        }
    }
}
