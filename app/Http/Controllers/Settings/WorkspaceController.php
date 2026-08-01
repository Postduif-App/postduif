<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the workspace is called.
 *
 * Who is in it lives one screen over in WorkspaceMemberController, what they
 * are allowed to do in WorkspacePermissionController, and how it looks in
 * WorkspaceThemeController. All four were one page once, and it was never clear
 * which part of it you were looking at.
 */
class WorkspaceController extends Controller
{
    use ResolvesCurrentWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        // The slug is deliberately left alone. It sits in every URL, so
        // regenerating it on a rename would break links and bookmarks people
        // have already shared.
        $workspace->update($validated);

        return back()->with('status', 'Instellingen opgeslagen.');
    }
}
