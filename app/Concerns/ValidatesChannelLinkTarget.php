<?php

namespace App\Concerns;

use App\Models\Channel;
use App\Workflows\Triggers\ButtonTrigger;
use Illuminate\Validation\Rule;

/**
 * What a button in the bar above a channel may point at.
 *
 * Shared between adding one and changing one, and not for tidiness: these rules
 * are the whole of "a button does exactly one thing". A second copy that drifts
 * is a button that opens a URL *and* starts a workflow, which is a row the
 * database refuses — so the drift would surface as a 500 rather than as a
 * message under the field.
 */
trait ValidatesChannelLinkTarget
{
    /**
     * The rules for the pair of columns, with `sometimes` where a change is
     * allowed to leave one of them out.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function targetRules(?Channel $channel, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'url' => [
                ...$sometimes,
                /*
                 * One or the other, never both. required_without alone would
                 * accept a button carrying a URL and a workflow, and the bar
                 * would then have to invent which of the two pressing it means.
                 */
                'required_without:workflow_id',
                'missing_with:workflow_id',
                'nullable',
                'string',
                'max:2048',
                /*
                 * http and https only. The bar is drawn for everyone who can
                 * see the channel, guests included, so a "javascript:" or
                 * "data:" here would be an admin handing themselves a way to
                 * run something in every reader's browser.
                 */
                'url:http,https',
            ],
            'workflow_id' => [
                ...$sometimes,
                'required_without:url',
                'nullable',
                'integer',
                /*
                 * Of this workspace, and on the button trigger. The first is
                 * the obvious one; the second is what keeps the bar honest —
                 * a keyword workflow attached to a button would be started with
                 * none of the things it reads, and fail on its first step.
                 */
                Rule::exists('workflows', 'id')
                    ->where('workspace_id', $channel?->workspace_id)
                    ->where('trigger_type', ButtonTrigger::key()),
            ],
        ];
    }
}
