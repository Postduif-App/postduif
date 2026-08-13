<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\FeatureGroup;
use App\Features\WorkspaceFeature;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * Which parts of the product this workspace offers.
 *
 * A screen of its own rather than a block on the rights page, and not because
 * the list is long: the two answer different questions. The rights page decides
 * who may do a thing; this decides whether the thing exists here at all — for
 * everybody, the owner included. Mixing them would let somebody switch off
 * contracts for the whole workspace while reaching for the attachment size.
 *
 * The same switches the admin panel has had all along (see
 * EditWorkspaceFeatures), handed to the person the workspace belongs to. Kept
 * as two screens rather than one shared one: a platform moderator edits any
 * workspace by id and needs no ability, an owner edits their own and holds one.
 */
class WorkspaceFeatureController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request, 'manageFeatures');

        $states = $workspace->featureStates();

        return Inertia::render('settings/workspace-features', [
            'workspace' => ['name' => $workspace->name],

            /*
             * In the order of WorkspaceFeature::ALL, which is a choice the
             * application makes — the page draws them per group but never
             * reorders within one.
             */
            'features' => array_map(fn (string $feature): array => [
                'key' => $feature::key(),
                'label' => $feature::label(),
                'description' => $feature::description(),
                'group' => $feature::group()->value,
                'enabled' => $states[$feature] ?? $feature::default(),
                /*
                 * Whether a fresh workspace would have had it. Shown beside the
                 * three that start off, so somebody meeting the list for the
                 * first time reads "nobody switched this off" rather than
                 * "somebody took this away from me".
                 */
                'onByDefault' => $feature::default(),
            ], WorkspaceFeature::ALL),

            'groups' => array_map(fn (FeatureGroup $group): array => [
                'value' => $group->value,
                'label' => $group->label(),
                'description' => $group->description(),
            ], FeatureGroup::cases()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request, 'manageFeatures');

        $keys = array_map(
            fn (string $feature): string => $feature::key(),
            WorkspaceFeature::ALL,
        );

        /*
         * Required rather than sometimes, unlike its neighbours on the rights
         * page. There the form sends a handful of fields out of many and what
         * is missing should stay as it was; here the list *is* the form, and a
         * request that names three features is a request that wants the other
         * fifteen off. Making that explicit is what stops a half-sent form from
         * silently emptying the workspace.
         */
        $validated = $request->validate([
            'features' => ['present', 'array'],

            /*
             * Nullable beside the whitelist, and for the reason the blocked
             * words are: the form carries one empty entry so that "everything
             * off" is a thing it can say at all. An unticked box sends nothing,
             * so a page with no box ticked would otherwise send no `features`
             * key and fall over the rule above. ConvertEmptyStringsToNull has
             * already made that entry a null by the time this runs.
             */
            'features.*' => ['nullable', Rule::in($keys)],
        ]);

        $enabled = array_filter($validated['features'], is_string(...));

        foreach (WorkspaceFeature::ALL as $feature) {
            in_array($feature::key(), $enabled, true)
                ? Feature::for($workspace)->activate($feature)
                : Feature::for($workspace)->deactivate($feature);
        }

        return back()->with('status', __('flashes.settings.features_saved'));
    }
}
