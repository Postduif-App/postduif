<?php

namespace App\Http\Middleware;

use App\Actions\Workspace\BuildThemeStyles;
use App\Enums\Availability;
use App\Enums\WorkspaceFont;
use App\Enums\WorkspaceRole;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $workspace = $request->user()
            ?->workspaces()
            ->oldest('workspace_user.joined_at')
            ->first();

        $role = $workspace === null ? null : WorkspaceRole::from($workspace->membership->role);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                /*
                 * Beside the user rather than appended to it: an appended
                 * attribute rides along on every serialisation of a user,
                 * including the admin panel's lists, and this is only ever
                 * drawn for the signed-in member.
                 */
                'avatarUrl' => $request->user()?->avatarUrl(),
                // The settings screens live inside the application rather than
                // in a shell of their own, so they need to know which workspace
                // you came from — for the way back, and to name the section.
                'workspace' => $workspace === null ? null : [
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                ],
                // Decides whether the settings navigation lists the workspace
                // section at all. Better than listing a screen that then
                // refuses to open.
                'canManageWorkspace' => $role?->canManageWorkspace() ?? false,
                'canInviteToWorkspace' => $role?->canInviteMembers() ?? false,
                // The role itself, so a screen can say "you are here as a
                // guest" without inferring it from a handful of false flags.
                // Every actual permission still comes from those flags — this
                // is for what the interface tells you, not what it lets you do.
                'workspaceRole' => $role?->value,
                // The status picker sits in the user menu, which is on every
                // screen — so its options travel with the menu rather than each
                // page having to remember to send them. The labels stay in the
                // enum, one copy for the whole application.
                'availabilityOptions' => collect(Availability::cases())
                    ->map(fn (Availability $availability): array => [
                        'value' => $availability->value,
                        'label' => $availability->label(),
                        'description' => $availability->description(),
                    ])->all(),
            ],
            // The workspace's own accent and letter, ready to be dropped into a
            // <style> tag. Shared rather than fetched per screen: the theme is
            // true of the whole application, and a page that forgot to ask
            // would silently fall back to the default look.
            'theme' => [
                'css' => $workspace === null
                    ? ''
                    : app(BuildThemeStyles::class)->handle($workspace),
                // The root template loads the face this alias names and nothing
                // else. Null is a real answer, not a missing one: it is what
                // the system font asks for, so it must not fall through to the
                // default the way a signed-out visitor does.
                'font' => $workspace === null
                    ? WorkspaceFont::InstrumentSans->alias()
                    : $workspace->font->alias(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
