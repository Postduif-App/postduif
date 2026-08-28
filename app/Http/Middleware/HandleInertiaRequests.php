<?php

namespace App\Http\Middleware;

use App\Actions\Workspace\BuildThemeStyles;
use App\Enums\Availability;
use App\Enums\PlatformEdition;
use App\Enums\WorkspaceAbility;
use App\Enums\WorkspaceFont;
use App\Features\Contracts as ContractsFeature;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
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
    /**
     * Every lang file for the current locale, flattened to "group.key".
     *
     * Keyed off the Dutch directory rather than the current one: nl is the
     * source language, so a group that only exists there should still be
     * offered — Laravel falls back per line, and a group skipped here would
     * leave the frontend with nothing to fall back to.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        $lines = [];

        foreach (glob(lang_path('nl/*.php')) ?: [] as $file) {
            $group = basename($file, '.php');

            foreach (Arr::dot(Lang::get($group)) as $key => $line) {
                $lines["{$group}.{$key}"] = $line;
            }
        }

        return $lines;
    }

    /**
     * The member behind an impersonated session, as the bar needs them.
     *
     * A name and nothing else. The impersonator's own record has no business
     * travelling to a browser that is currently signed in as somebody else —
     * what the bar has to say is "je bent eigenlijk Sebastiaan", and that is
     * one string.
     *
     * @return array{name: string}|null
     */
    private function impersonator(): ?array
    {
        $impersonation = app(Impersonation::class);

        if (! $impersonation->isActive()) {
            return null;
        }

        $impersonator = $impersonation->impersonator();

        return $impersonator === null ? null : ['name' => $impersonator->name];
    }

    public function share(Request $request): array
    {
        /*
         * Asked once and held, rather than a dozen times down the array below.
         * A visitor who is not signed in is null here, and every line that
         * follows says so in one shape — which is also what lets the workspace
         * and the role be worked out before anything is drawn.
         */
        $user = $request->user();

        $workspace = $user
            ?->workspaces()
            ->oldest('workspace_user.joined_at')
            ->first();

        $role = $user === null ? null : $workspace?->roleFor($user);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            /*
             * Every line, flattened, in the language HandleLocale settled on.
             *
             * All of them rather than per page: the set is small enough to be
             * cheaper than working out which page needs which, and a component
             * that moves between pages would otherwise arrive somewhere its
             * words are missing.
             */
            'translations' => $this->translations(),
            'locale' => app()->getLocale(),

            // Whether the sign-up page is answering, so the login screen knows
            // not to offer a way to a door that is shut.
            'registrationOpen' => (bool) config('auth.registration_open'),

            /*
             * Of de publieke site hier bestaat. Gedeeld en niet per pagina
             * meegegeven, want de shell om de API-pagina heeft het antwoord
             * nodig: het woordmerk daar wijst naar / en dat adres stuurt op een
             * zelfgehoste installatie door naar het inlogscherm.
             */
            'marketingSite' => PlatformEdition::current()->showsMarketingSite(),
            'auth' => [
                'user' => $user,
                /*
                 * Who is really sitting there, when it is not the person above.
                 *
                 * Shared rather than left to a page, because the bar it draws
                 * has to be on every screen at once: an impersonated session
                 * looks exactly like an ordinary one, and the one thing that
                 * must never happen is somebody forgetting they are somebody
                 * else and writing a message as them.
                 *
                 * Null in the ordinary case, which is also what keeps this
                 * free: it is a session lookup and no query at all until an
                 * impersonation is actually running.
                 */
                'impersonator' => $this->impersonator(),
                /*
                 * Beside the user rather than appended to it: an appended
                 * attribute rides along on every serialisation of a user,
                 * including the admin panel's lists, and this is only ever
                 * drawn for the signed-in member.
                 */
                'avatarUrl' => $user?->avatarUrl(),
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
                'canManageWorkspace' => $role?->allows(WorkspaceAbility::ManageWorkspace) ?? false,
                'canInviteToWorkspace' => $role?->allows(WorkspaceAbility::InviteMembers) ?? false,
                /*
                 * Whether the ledenlijst is theirs to open. Its own flag beside
                 * the two above and for the same reason they are separate: a
                 * role may arrange who comes and goes without running the
                 * workspace, and one that invites people is not thereby allowed
                 * to show them the door.
                 */
                'canManageMembers' => $role?->allows(WorkspaceAbility::ManageMembers) ?? false,
                /*
                 * Whether the features screen is theirs to open. Its own flag
                 * rather than folded into canManageWorkspace, because it is the
                 * one workspace screen an administrator does not get with the
                 * job — see WorkspaceAbility::ManageFeatures.
                 */
                'canManageFeatures' => $role?->allows(WorkspaceAbility::ManageFeatures) ?? false,
                /*
                 * Whether this workspace has workflows at all, so the settings
                 * navigation can leave the link out rather than offer a screen
                 * that answers 404. One flag rather than the whole feature set:
                 * every other feature is decided on the page that needs it, and
                 * this is the only one that has to be known before a link is
                 * drawn.
                 */
                'canManageWorkflows' => $user !== null
                    && $workspace !== null
                    && $user->can('manageWorkflows', $workspace),
                /*
                 * And the same question for the contract webhooks, for exactly
                 * the same reason: that screen answers 404 where contracts are
                 * switched off, and a link that refuses to open is worse than
                 * no link. Asked as two conditions rather than through a policy
                 * method because there is nothing more to it than "runs the
                 * workspace" and "has contracts" — see
                 * ContractWebhookController, which asks the same two.
                 */
                'canManageContractWebhooks' => $workspace !== null
                    && ($role?->allows(WorkspaceAbility::ManageWorkspace) ?? false)
                    && $workspace->hasFeature(ContractsFeature::class),
                // The role itself, so a screen can say "you are here as a
                // guest" without inferring it from a handful of false flags.
                // Every actual permission still comes from those flags — this
                // is for what the interface tells you, not what it lets you do.
                /*
                  * Whether the person is here from outside, rather than the
                  * name of their role. The interface asked "is this a guest"
                  * and a name cannot answer that once a workspace writes its
                  * own roles — a "Leverancier" is every bit as external and
                  * matches no string the browser knows.
                  */
                'workspaceIsExternal' => $role->is_external ?? false,
                /*
                 * The clock, for the button in the user menu.
                 *
                 * Shared rather than left to the screen that shows the hours,
                 * because clocking in is something you do from wherever you
                 * happen to be — the same reason the status picker lives here.
                 * Null when this workspace has tijdregistratie switched off or
                 * the person is a guest in it, which is what lets the menu
                 * leave the item out rather than draw one that refuses.
                 *
                 * The open shift is only looked up once the policy has said
                 * yes, so a workspace without the feature pays no query for it.
                 */
                'timeclock' => $user !== null && $workspace !== null && $user->can('clock', $workspace)
                    ? ['runningSince' => $user->openShiftIn($workspace)?->started_at->toIso8601String()]
                    : null,
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
            /*
             * Whether the member list on the right is open. Read here rather
             * than from the browser after mount, for the same reason as the
             * sidebar beside it: the first paint has to be right, and a panel
             * that flicks open before closing again is worse than one that
             * forgets.
             *
             * Closed unless it says otherwise — the opposite default from the
             * sidebar, because this panel is the extra one.
             */
            'memberPanelOpen' => $request->cookie('member_panel_state') === 'true',
        ];
    }
}
