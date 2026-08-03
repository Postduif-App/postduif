<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\AttachmentType;
use App\Enums\BroadcastMentionPolicy;
use App\Enums\ChannelCreationPolicy;
use App\Features\Transfers;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What members of this workspace are allowed to do.
 *
 * Split off from WorkspaceController, which had grown into one screen holding
 * the name, the rules and the paint. These are different questions asked at
 * different moments — you name a workspace once and adjust its rules for years
 * — and one long form made the rules easy to change by accident on the way to
 * something else.
 */
class WorkspacePermissionController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace-permissions', [
            'workspace' => [
                'name' => $workspace->name,
                'broadcastMentions' => $workspace->broadcast_mentions->value,
                'channelCreation' => $workspace->channel_creation->value,
                'uploadsEnabled' => $workspace->uploads_enabled,
                'allowedAttachmentTypes' => array_map(
                    fn (AttachmentType $type): string => $type->value,
                    $workspace->allowedAttachmentTypes(),
                ),
                'maxAttachmentKb' => $workspace->max_attachment_kb,
                'linkPreviewsEnabled' => $workspace->link_previews_enabled,

                /*
                 * The ceilings on a transfer, and whether they mean anything
                 * here. A limit shown for a feature this workspace does not
                 * have reads as a promise it can be used, so the screen asks
                 * first and hides the pair when the answer is no.
                 */
                'transfersEnabled' => $workspace->hasFeature(Transfers::class),
                'maxTransferKb' => $workspace->max_transfer_kb,
                'maxTransferDays' => $workspace->max_transfer_days,
            ],
            'attachmentTypeOptions' => collect(AttachmentType::cases())
                ->map(fn (AttachmentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'hint' => $type->hint(),
                ])->all(),
            'broadcastMentionOptions' => collect(BroadcastMentionPolicy::cases())
                ->map(fn (BroadcastMentionPolicy $policy): array => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                ])->all(),
            'channelCreationOptions' => collect(ChannelCreationPolicy::cases())
                ->map(fn (ChannelCreationPolicy $policy): array => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $validated = $request->validate([
            'broadcast_mentions' => ['required', new Enum(BroadcastMentionPolicy::class)],
            'channel_creation' => ['required', new Enum(ChannelCreationPolicy::class)],
            /*
             * Sometimes rather than required: this endpoint has served the two
             * rules above since before files existed, and a request that says
             * nothing about sharing should leave it as it was rather than turn
             * it off. The form always sends it — a hidden 0 beside the tickbox,
             * because an unticked box sends nothing at all.
             */
            'uploads_enabled' => ['sometimes', 'boolean'],

            /*
             * Sometimes, for the same reason as the switch above: this endpoint
             * served the two rules long before either setting existed, and a
             * request that says nothing about previews should leave them as
             * they are rather than switch them off.
             */
            'link_previews_enabled' => ['sometimes', 'boolean'],

            /*
             * Only asked for while sharing is on. The form hides both fields
             * when the switch is off, and what is hidden is left as it was
             * rather than wiped — turning sharing back on should find the same
             * choices, not an empty list somebody has to fill in again.
             *
             * Required while it is on, though: "files are allowed but no kind
             * is" is not a state anybody meant to be in.
             */
            'allowed_attachment_types' => ['required_if:uploads_enabled,1', 'array', 'min:1'],
            'allowed_attachment_types.*' => [new Enum(AttachmentType::class)],

            /*
             * Asked in megabytes, stored in kilobytes.
             *
             * Megabytes are what somebody setting a limit thinks in; kilobytes
             * are what the upload rule counts in. Converting once here keeps
             * the mismatch in one place instead of in every form and every
             * validator that later reads the setting.
             *
             * One to two hundred: below a megabyte no screenshot fits, and
             * above it PHP's own upload limit decides anyway — a setting that
             * quietly does not apply is worse than no setting.
             */
            'max_attachment_mb' => ['required_if:uploads_enabled,1', 'integer', 'min:1', 'max:200'],

            /*
             * The transfer ceilings, sometimes for the same reason as the rest:
             * a workspace without the feature sends neither field, and what is
             * not sent is left alone rather than reset.
             *
             * Up to ten gigabytes, which is where a browser upload stops being
             * something a person waits out. The day ceiling caps how long a
             * link may be asked to live — it is what stops the required expiry
             * date on a transfer from being answered with "in ten years", and
             * ninety days is already generous for something meant to be picked
             * up.
             */
            'max_transfer_mb' => ['sometimes', 'integer', 'min:1', 'max:10240'],
            'max_transfer_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $workspace->update([
            ...Arr::except($validated, ['max_attachment_mb', 'max_transfer_mb']),
            ...isset($validated['max_attachment_mb'])
                ? ['max_attachment_kb' => $validated['max_attachment_mb'] * 1024]
                : [],
            ...isset($validated['max_transfer_mb'])
                ? ['max_transfer_kb' => $validated['max_transfer_mb'] * 1024]
                : [],
        ]);

        return back()->with('status', 'Rechten opgeslagen.');
    }
}
