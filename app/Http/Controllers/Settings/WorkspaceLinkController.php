<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Workspace;
use App\Models\WorkspaceLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's own buttons.
 *
 * Validation sits here rather than in a FormRequest, unlike the channel links
 * next door. Those hang off a route parameter a request can read; these belong
 * to whichever workspace the member is managing, which is a question only
 * ResolvesCurrentWorkspace answers — and a request that resolved it a second
 * time would be a second answer to it. The same shape as CustomEmojiController
 * and WorkspaceRoleController, which have the same problem.
 */
class WorkspaceLinkController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Enough for the shortcuts an organisation actually keeps, few enough that
     * the workspace menu stays a menu rather than a page.
     */
    private const MAX_LINKS = 20;

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace-links', [
            'links' => $workspace->links()->with('roles:id')->get()
                ->map(fn (WorkspaceLink $link): array => [
                    'id' => $link->id,
                    'label' => $link->label,
                    'url' => $link->url,
                    'emoji' => $link->emoji,
                    'roleIds' => $link->roles->pluck('id')->all(),
                ])->all(),

            /*
             * Every role, including the external ones. Which of them a link is
             * for is the whole setting, so hiding the guest roles here would
             * hide the case this screen exists for.
             */
            'roles' => $workspace->roles()->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'isExternal' => $role->is_external,
                ])->all(),

            'maxLinks' => self::MAX_LINKS,
            'workspace' => $workspace->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if(
            $workspace->links()->count() >= self::MAX_LINKS,
            422,
            __('workspace_links.too_many', ['count' => self::MAX_LINKS]),
        );

        $validated = $this->validated($request, $workspace);

        $link = $workspace->links()->create([
            'label' => $validated['label'],
            'url' => $validated['url'],
            'emoji' => $validated['emoji'],
            // Onto the end. Somebody adding a button has said nothing about
            // where it belongs, and the top of the menu is the one place it
            // certainly does not.
            'position' => ((int) $workspace->links()->max('position')) + 1,
        ]);

        $link->roles()->sync($validated['role_ids']);

        return back()->with('status', __('flashes.workspace_link.added', ['label' => $link->label]));
    }

    public function update(Request $request, WorkspaceLink $workspaceLink): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorizeLink($workspace, $workspaceLink);

        $validated = $this->validated($request, $workspace);

        $workspaceLink->update([
            'label' => $validated['label'],
            'url' => $validated['url'],
            'emoji' => $validated['emoji'],
        ]);

        $workspaceLink->roles()->sync($validated['role_ids']);

        return back()->with('status', __('flashes.workspace_link.saved', ['label' => $workspaceLink->label]));
    }

    public function destroy(Request $request, WorkspaceLink $workspaceLink): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorizeLink($workspace, $workspaceLink);

        $label = $workspaceLink->label;

        $workspaceLink->delete();

        return back()->with('status', __('flashes.workspace_link.removed', ['label' => $label]));
    }

    /**
     * Put the buttons in the given order.
     *
     * The whole list at once rather than a position per button — moving one
     * changes where the others sit, and saving those one request at a time
     * leaves the menu in an order nobody asked for as soon as one of them
     * fails. The same shape as ChannelLinkController::reorder, ids that are not
     * ours dropped for the same reason: they can only come from a list that has
     * since changed, and the answer to that is to order what is actually there.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ours = $workspace->links()->pluck('id')->flip();

        DB::transaction(function () use ($workspace, $validated, $ours): void {
            foreach (array_values($validated['ids']) as $position => $id) {
                if ($ours->has($id)) {
                    $workspace->links()->whereKey($id)->update(['position' => $position]);
                }
            }
        });

        return back();
    }

    /**
     * @return array{label: string, url: string, emoji: string|null, role_ids: list<int>}
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'url' => [
                'required',
                'string',
                'max:2048',
                /*
                 * http and https only. This button is drawn for everybody the
                 * roles below name, guests included, so a "javascript:" or
                 * "data:" here would be a beheerder handing themselves
                 * something that runs in every one of those readers' browsers.
                 * The same rule guards the channel links — see
                 * ValidatesChannelLinkTarget.
                 */
                'url:http,https',
            ],
            // A single emoji, or nothing. Length rather than a pattern: an
            // emoji is anywhere from one code point to a family of seven joined
            // by zero-width joiners, and the column only has to survive it.
            'emoji' => ['nullable', 'string', 'max:16'],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => [
                'integer',
                /*
                 * Of this workspace. Without the scope a beheerder could point
                 * a button at a role id belonging to somebody else's workspace:
                 * harmless to draw, since nobody here holds it, but it would
                 * put a row in the pivot naming a role this workspace has no
                 * business knowing exists.
                 */
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $workspace->id),
            ],
        ], [
            'label.required' => __('requests.workspace_link.label_required'),
            'url.required' => __('requests.workspace_link.url_required'),
            'url.url' => __('requests.workspace_link.url_scheme'),
            'role_ids.*.exists' => __('requests.workspace_link.role_unknown'),
        ]);

        return [
            'label' => trim($validated['label']),
            'url' => trim($validated['url']),
            'emoji' => $validated['emoji'] ?? null,
            'role_ids' => array_values(array_unique(array_map('intval', $validated['role_ids']))),
        ];
    }

    /**
     * That this link belongs to the workspace being managed.
     *
     * A 404 rather than a 403, as with the emoji next door: an id from another
     * workspace is not something this member was refused, it is something they
     * have no way of knowing exists.
     */
    private function authorizeLink(Workspace $workspace, WorkspaceLink $link): void
    {
        abort_unless($link->workspace_id === $workspace->id, 404);
    }
}
