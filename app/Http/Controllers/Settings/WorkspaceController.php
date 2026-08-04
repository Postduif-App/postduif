<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\StoreAvatar;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\AttachmentType;
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
                'avatarUrl' => $workspace->avatarUrl(),
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

        return back()->with('status', __('flashes.settings.saved'));
    }

    /**
     * The workspace's logo.
     *
     * Its own endpoints rather than a field on the form above: a picture is
     * uploaded the moment it is chosen, and folding a file into a form that
     * saves a name would make renaming the workspace re-upload the logo.
     *
     * currentWorkspace('manage') is the permission — the same one that opens
     * this screen at all.
     */
    public function storeAvatar(Request $request, StoreAvatar $storeAvatar): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request, 'manage');

        $request->validate([
            'avatar' => [
                'required',
                'image',
                'max:2048',
                // The image group, so an SVG cannot come in — a script in a
                // costume, exactly as with attachments.
                'mimetypes:'.implode(',', AttachmentType::Images->mimeTypes()),
            ],
        ], [
            'avatar.mimetypes' => __('requests.image.type'),
        ]);

        $storeAvatar->handle($workspace, $request->file('avatar'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.settings.logo_saved')]);

        return back();
    }

    public function destroyAvatar(Request $request, StoreAvatar $storeAvatar): RedirectResponse
    {
        $storeAvatar->remove($this->currentWorkspace($request, 'manage'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.settings.logo_removed')]);

        return back();
    }
}
