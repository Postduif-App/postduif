<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\WorkspaceAbility;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roles a workspace writes for itself.
 *
 * Everything here is guarded by one sentence: nobody may make, change or hand
 * out a role that reaches past their own. It is asked of every write rather
 * than of the screen, because this is the screen that makes roles — a rule
 * enforced by hiding a tickbox is a rule enforced by nothing.
 */
class WorkspaceRoleController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Enough for a workspace that means it, few enough that the list stays a
     * thing somebody reads rather than searches.
     */
    private const MAX_ROLES = 20;

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorize('manage', $workspace);

        $viewer = $request->user();
        $own = $workspace->roleFor($viewer);

        return Inertia::render('settings/workspace-roles', [
            'roles' => $workspace->roles()->withCount('holders')->inOrder()->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'key' => $role->key,
                    'name' => $role->name,
                    'isExternal' => $role->is_external,
                    'isSystem' => $role->is_system,
                    'holders' => $role->holders_count,
                    'abilities' => $role->abilities()
                        ->map(fn (WorkspaceAbility $ability): string => $ability->value)
                        ->all(),
                    /*
                     * Whether this row may be touched at all, worked out here
                     * rather than in the browser: the rule about reaching past
                     * your own role lives in one place, and the interface only
                     * renders the answer.
                     */
                    'editable' => $own !== null && $role->isUnder($own),
                ])->all(),

            /*
             * The catalogue, with what each right means. Sent whole rather than
             * filtered to what this member holds — a right they cannot grant is
             * still worth seeing, greyed out, because "why can I not tick that"
             * is answered by seeing it.
             */
            'abilities' => array_map(fn (WorkspaceAbility $ability): array => [
                'value' => $ability->value,
                'label' => $ability->label(),
                'description' => $ability->description(),
                'held' => $own?->allows($ability) ?? false,
            ], WorkspaceAbility::cases()),

            'workspace' => $workspace->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorize('manage', $workspace);

        abort_if(
            $workspace->roles()->count() >= self::MAX_ROLES,
            422,
            __('workspace_roles.too_many', ['count' => self::MAX_ROLES]),
        );

        $validated = $this->validated($request, $workspace);

        $this->guardAgainstReachingUp($request, $workspace, $validated['abilities']);

        $role = $workspace->roles()->create([
            /*
             * A key of its own, derived once and never shown. The name is what
             * a workspace renames; the key is what everything that has to
             * recognise a role across a rename reads.
             */
            'key' => 'custom-'.str()->random(12),
            'name' => $validated['name'],
            'is_external' => $validated['is_external'],
            'is_system' => false,
            /*
             * At the bottom. Standing is what decides who may touch whom, so a
             * new role starting anywhere else would be this screen handing out
             * seniority nobody asked for.
             */
            'position' => (int) $workspace->roles()->max('position') + 1,
            'abilities' => $validated['abilities'],
        ]);

        return back()->with('status', __('flashes.role.created', ['name' => $role->name]));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorizeRole($request, $workspace, $role);

        $validated = $this->validated($request, $workspace, $role);

        $this->guardAgainstReachingUp($request, $workspace, $validated['abilities']);

        $role->update([
            'name' => $validated['name'],
            'abilities' => $validated['abilities'],
            /*
             * Whether a role is from outside is fixed once somebody holds it.
             * Flipping it would move people across the line that decides which
             * channels exist for them — silently, from a screen about
             * tickboxes.
             */
            ...$role->is_system || $role->holders()->exists()
                ? []
                : ['is_external' => $validated['is_external']],
        ]);

        return back()->with('status', __('flashes.role.saved', ['name' => $role->name]));
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorizeRole($request, $workspace, $role);

        abort_if($role->is_system, 422, __('workspace_roles.system_role'));

        if ($role->holders()->exists()) {
            throw ValidationException::withMessages([
                'role' => __('workspace_roles.still_held'),
            ]);
        }

        $role->delete();

        return back()->with('status', __('flashes.role.deleted', ['name' => $role->name]));
    }

    /**
     * @return array{name: string, is_external: bool, abilities: list<string>}
     */
    private function validated(Request $request, Workspace $workspace, ?Role $role = null): array
    {
        /** @var array{name: string, is_external: bool, abilities: list<string>} */
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('workspace_roles', 'name')
                    ->where('workspace_id', $workspace->id)
                    ->ignore($role?->id),
            ],
            'is_external' => ['required', 'boolean'],
            'abilities' => ['present', 'array'],
            'abilities.*' => [Rule::enum(WorkspaceAbility::class)],
        ]);
    }

    /**
     * That this role belongs here, and that it does not stand above the person
     * editing it.
     */
    private function authorizeRole(Request $request, Workspace $workspace, Role $role): void
    {
        abort_unless($role->workspace_id === $workspace->id, 404);

        $this->authorize('manage', $workspace);

        $own = $workspace->roleFor($request->user());

        abort_unless($own !== null && $role->isUnder($own), 403);
    }

    /**
     * The rule this whole screen turns on: you cannot write into a role a right
     * you do not hold yourself.
     *
     * Asked of what was sent, before anything is stored. Checking afterwards
     * would mean a role that reaches too far existing for the length of a
     * request — and without a transaction around it, existing for good.
     *
     * @param  list<string>  $abilities
     */
    private function guardAgainstReachingUp(Request $request, Workspace $workspace, array $abilities): void
    {
        $own = $workspace->roleFor($request->user());

        $beyond = collect($abilities)
            ->map(fn (string $ability): ?WorkspaceAbility => WorkspaceAbility::tryFrom($ability))
            ->filter()
            ->reject(fn (WorkspaceAbility $ability): bool => $own?->allows($ability) ?? false);

        if ($beyond->isNotEmpty()) {
            throw ValidationException::withMessages([
                'abilities' => __('workspace_roles.beyond_your_own'),
            ]);
        }
    }
}
