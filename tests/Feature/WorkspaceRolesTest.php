<?php

use App\Enums\ChannelCreationPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A workspace with no roles is one nobody can be a member of, so the four are
 * an invariant of the model rather than a step somebody remembers to take.
 */
it('gives a new workspace the four roles it starts with', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->roles()->pluck('key')->all())
        ->toBe(array_map(fn (SystemRole $role): string => $role->value, SystemRole::cases()));
});

it('starts each of them with what the code already said they may do', function () {
    $workspace = Workspace::factory()->create();

    $abilities = fn (SystemRole $role): array => $workspace->roles()
        ->where('key', $role->value)
        ->first()
        ->abilities()
        ->map(fn (WorkspaceAbility $ability): string => $ability->value)
        ->all();

    expect($abilities(SystemRole::Admin))->toContain(WorkspaceAbility::ManageWorkspace->value)
        // A member does the ordinary things and administers nothing.
        ->and($abilities(SystemRole::Member))->toContain(WorkspaceAbility::CreateChannels->value)
        ->and($abilities(SystemRole::Member))->not->toContain(WorkspaceAbility::ManageWorkspace->value)
        /*
         * And a guest holds nothing at all. Which is not the same as being kept
         * out — that is the column below, and it is the one that decides what
         * they can even see.
         */
        ->and($abilities(SystemRole::Guest))->toBe([]);
});

it('says outside-ness in a column rather than in the bag', function () {
    $workspace = Workspace::factory()->create();

    $guest = $workspace->roles()->where('key', SystemRole::Guest->value)->first();

    /*
     * The load-bearing distinction in this whole table. Channel visibility is
     * decided in SQL, so "may they see the workspace at all" has to be
     * answerable in a join — a right in a jsonb bag cannot be, and the day
     * somebody writes a query that forgets to unpack it is the day a guest sees
     * every public channel.
     */
    expect($guest->is_external)->toBeTrue()
        ->and($workspace->roles()->where('is_external', false)->count())->toBe(3);
});

it('holds nothing it was not given, including a right invented later', function () {
    $role = new Role(['abilities' => [WorkspaceAbility::SeeMembers->value, 'iets-wat-niet-bestaat']]);

    expect($role->allows(WorkspaceAbility::SeeMembers))->toBeTrue()
        ->and($role->allows(WorkspaceAbility::ManageWorkspace))->toBeFalse()
        /*
         * A value left behind by a right that has since been taken out of the
         * application must not turn up on a screen as a tickbox nothing
         * enforces.
         */
        ->and($role->abilities()->pluck('value')->all())->toBe([WorkspaceAbility::SeeMembers->value]);
});

/**
 * The rule that keeps custom roles from being a way to promote yourself: nobody
 * may hand out, or write into a role, a right they do not hold. This is the
 * question behind it, and it is asked of the abilities rather than of any
 * notion of seniority — roles here are a set, not a ladder.
 */
it('knows whether one role stays inside another', function () {
    $workspace = Workspace::factory()->create();

    $owner = $workspace->roles()->where('key', SystemRole::Owner->value)->first();
    $member = $workspace->roles()->where('key', SystemRole::Member->value)->first();

    expect($member->isWithin($owner))->toBeTrue()
        ->and($owner->isWithin($member))->toBeFalse()
        // And a role is trivially within itself, which is what lets somebody
        // hand out their own role without a special case.
        ->and($member->isWithin($member))->toBeTrue();
});

it('points a new member at the role row, not only at the word', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $workspace->members()->attach($user->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $membership = $workspace->members()->whereKey($user->id)->first()->membership;

    expect($membership->workspaceRole->key)->toBe(SystemRole::Member->value);
});

it('moves the pointer along when somebody changes role', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $workspace->members()->attach($user->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $workspace->members()->updateExistingPivot($user->id, ['role' => SystemRole::Admin->value]);

    $membership = $workspace->fresh()->members()->whereKey($user->id)->first()->membership;

    /*
     * The pointer follows the word rather than filling itself in once. A
     * membership that kept naming the role somebody used to have is the bug
     * this exists to prevent — and it would only surface after the reads move
     * over, which is exactly when it would be hardest to find.
     */
    expect($membership->workspaceRole->key)->toBe(SystemRole::Admin->value);
});

it('reads a workspace that had already decided who may open a channel', function () {
    $workspace = Workspace::factory()->create([
        'channel_creation' => ChannelCreationPolicy::Admins,
    ]);

    /*
     * The seed follows the workspace's own setting rather than the role's
     * default. A workspace that has said "only administrators" would otherwise
     * find that quietly undone by the migration that was supposed to preserve
     * it — see the migration, which reads the same two columns.
     */
    // Re-seeded rather than read straight off the factory: what is under test
    // is the translation, and the factory has already run it once.
    $workspace->roles()->delete();
    $workspace->seedSystemRoles();

    expect($workspace->roles()->where('key', SystemRole::Member->value)->first()->abilities()->pluck('value')->all())
        ->not->toContain(WorkspaceAbility::CreateChannels->value);
});

it('names every right in both languages', function () {
    foreach (['nl', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (WorkspaceAbility::cases() as $ability) {
            expect($ability->label())->not->toContain('enums.', "{$ability->value} heeft geen naam in {$locale}")
                ->and($ability->description())->not->toContain('enums.', "{$ability->value} heeft geen uitleg in {$locale}");
        }
    }
});

it('points somebody at a role even when nobody named one', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    /*
     * No role in the call. Plenty of places add somebody this way and lean on
     * the column's own default, which is exactly the case that has to end up
     * pointing somewhere — a membership with no role is one no policy can
     * answer for.
     */
    $workspace->members()->attach($user->id, ['joined_at' => now()]);

    $membership = $workspace->members()->whereKey($user->id)->first()->membership;

    expect($membership->role)->toBe(SystemRole::Member->value)
        ->and($membership->workspaceRole->key)->toBe(SystemRole::Member->value);
});

/**
 * The rule that keeps custom roles from being a way to promote yourself, at the
 * only place it can be enforced: an administrator may make roles, so no screen
 * can close this path — only the policy can.
 */
it('will not let somebody hand out a role that stands above their own', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $owner = $workspace->roles()->where('key', SystemRole::Owner->value)->first();

    /*
     * Owner and administrator hold exactly the same rights here — the seed gives
     * both all seven — so the rights alone say this is lateral. What refuses it
     * is standing: owner sits above administrator in this workspace's order.
     */
    expect($admin->can('grantRole', [$workspace, $owner]))->toBeFalse();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => $owner->id])
        ->assertForbidden();

    expect($workspace->roleFor($member)?->key)->toBe(SystemRole::Member->value);
});

it('will not let somebody invent a role with more than they hold and hand it out', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    // The administrator's own rights, minus the one that reaches every other.
    $workspace->roles()
        ->where('key', SystemRole::Admin->value)
        ->first()
        ->update(['abilities' => [WorkspaceAbility::SeeMembers->value]]);

    $invented = $workspace->roles()->create([
        'key' => 'stagiair',
        'name' => 'Stagiair',
        'position' => 9,
        'abilities' => WorkspaceAbility::values(),
    ]);

    /*
     * Below them in the order and beyond them in rights. Both questions have to
     * be asked, or "make a role and assign it" is a two-step path from
     * administrator to everything.
     */
    expect($admin->can('grantRole', [$workspace, $invented]))->toBeFalse();
});

it('lets somebody hand out a role that is genuinely below their own', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    $ordinary = $workspace->roles()->where('key', SystemRole::Member->value)->first();

    expect($admin->can('grantRole', [$workspace, $ordinary]))->toBeTrue();
});
