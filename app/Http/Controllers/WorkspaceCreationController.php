<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\CreateWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Making your own workspace.
 *
 * Somebody who has just signed up belongs nowhere: they were sent to /app,
 * which looked for a workspace, found none and answered 404. An account you
 * cannot do anything with is a strange thing to hand somebody one form after
 * they asked for it.
 *
 * Not under the workspace prefix, for the obvious reason — there is no
 * workspace to be under yet.
 */
class WorkspaceCreationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('workspaces/create', [
            /*
             * Whether they got here because they have none, or came looking on
             * purpose. It changes nothing about what the form does and
             * everything about how the page reads: the first is somebody who
             * has just arrived, the second knows exactly what they came for.
             */
            'isFirst' => $request->user()->workspaces()->doesntExist(),
        ]);
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * Only the name is asked for. The address is derived from it — see
             * CreateWorkspace — because a slug is a thing somebody has to be
             * taught to care about, and the first screen after signing up is
             * the worst possible place to teach it. It can be changed later in
             * the workspace settings.
             */
            'name' => ['required', 'string', 'max:60'],
        ]);

        $workspace = $createWorkspace->handle($request->user(), $validated['name']);

        return redirect()
            ->route('chat.index', $workspace)
            ->with('status', __('workspaces.created', ['name' => $workspace->name]));
    }
}
