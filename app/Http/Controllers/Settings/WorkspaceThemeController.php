<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the workspace looks: its accent and the letter it reads in.
 *
 * Both choices are made from a fixed list, so what this screen offers is
 * derived from the enums rather than listed here — a colour added to
 * WorkspaceAccent shows up here and nowhere else has to be told.
 */
class WorkspaceThemeController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace-theme', [
            'workspace' => [
                'name' => $workspace->name,
                'accent' => $workspace->accent->value,
                'font' => $workspace->font->value,
            ],
            // The swatch travels with the option so the picker can paint itself
            // in the real colour rather than in an approximation kept in sync
            // by hand on the other side.
            'accentOptions' => collect(WorkspaceAccent::cases())
                ->map(fn (WorkspaceAccent $accent): array => [
                    'value' => $accent->value,
                    'label' => $accent->label(),
                    'color' => $accent->swatch()['light']['color'],
                    'foreground' => $accent->swatch()['light']['foreground'],
                ])->all(),
            'fontOptions' => collect(WorkspaceFont::cases())
                ->map(fn (WorkspaceFont $font): array => [
                    'value' => $font->value,
                    'label' => $font->label(),
                    'stack' => $font->stack(),
                ])->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $workspace->update($request->validate([
            'accent' => ['required', new Enum(WorkspaceAccent::class)],
            'font' => ['required', new Enum(WorkspaceFont::class)],
        ]));

        return back()->with('status', 'Thema opgeslagen.');
    }
}
