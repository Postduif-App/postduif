<?php

use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Enums\WorkspaceRole;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('offers every accent and font, with what they look like', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, WorkspaceRole::Owner);

    actingAs($user)
        ->get(route('workspace.theme.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace-theme')
            ->where('workspace.accent', WorkspaceAccent::Neutral->value)
            ->where('workspace.font', WorkspaceFont::InstrumentSans->value)
            ->has('accentOptions', count(WorkspaceAccent::cases()))
            ->has('fontOptions', count(WorkspaceFont::cases()))
            ->where('accentOptions.0.color', WorkspaceAccent::Neutral->swatch()['light']['color'])
            ->where('fontOptions.0.stack', WorkspaceFont::InstrumentSans->stack())
        );
});

it('saves a chosen accent and font', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Admin);

    actingAs($user)
        ->patch(route('workspace.theme.update'), [
            'accent' => WorkspaceAccent::Indigo->value,
            'font' => WorkspaceFont::Figtree->value,
        ])
        ->assertRedirect();

    expect($workspace->fresh())
        ->accent->toBe(WorkspaceAccent::Indigo)
        ->font->toBe(WorkspaceFont::Figtree);
});

it('refuses a look that is not one of the offered ones', function (string $field, string $value) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);

    actingAs($user)
        ->patch(route('workspace.theme.update'), [
            'accent' => WorkspaceAccent::Neutral->value,
            'font' => WorkspaceFont::System->value,
            $field => $value,
        ])
        ->assertSessionHasErrors($field);

    expect($workspace->fresh())
        ->accent->toBe(WorkspaceAccent::Neutral)
        ->font->toBe(WorkspaceFont::InstrumentSans);
})->with([
    // The picker cannot produce these, so anything that arrives here is
    // somebody hand-writing the request — including, in the first case, an
    // attempt to write straight into the stylesheet.
    'losse css' => ['accent', 'red; --background: red'],
    'onbekende kleur' => ['accent', 'chartreuse'],
    'onbekend lettertype' => ['font', 'comic-sans'],
]);

it('refuses a theme change from a plain member', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);

    actingAs($user)
        ->patch(route('workspace.theme.update'), [
            'accent' => WorkspaceAccent::Rose->value,
            'font' => WorkspaceFont::Inter->value,
        ])
        ->assertForbidden();

    expect($workspace->fresh()->accent)->toBe(WorkspaceAccent::Neutral);
});

it('hands every screen the workspace stylesheet, both palettes and the letter', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);
    $workspace->update(['accent' => WorkspaceAccent::Emerald, 'font' => WorkspaceFont::Figtree]);

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('theme.font', 'figtree')
            ->where('theme.css', fn (string $css) => str_contains($css, WorkspaceAccent::Emerald->swatch()['light']['color'])
                && str_contains($css, WorkspaceAccent::Emerald->swatch()['dark']['color'])
                && str_contains($css, WorkspaceFont::Figtree->stack()))
        );
});

it('paints the first response before a single script runs', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);
    $workspace->update(['accent' => WorkspaceAccent::Rose]);

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('id="workspace-theme"', false)
        ->assertSee(WorkspaceAccent::Rose->swatch()['light']['color'], false);
});

it('asks for no webfont at all when the workspace reads in its own', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);
    $workspace->update(['font' => WorkspaceFont::System]);

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('theme.font', null))
        ->assertDontSee('instrument-sans-400', false);
});

it('falls back to the default look for a visitor who is in no workspace yet', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('theme.css', '')
            ->where('theme.font', 'instrument-sans'));
});
