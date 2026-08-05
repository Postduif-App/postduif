<?php

namespace App\Http\Controllers;

use App\Actions\Forms\PresentForm;
use App\Actions\Forms\SubmitForm;
use App\Models\Form;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Filling one in from inside the workspace.
 *
 * A screen of its own rather than a card that unfolds in the conversation,
 * which is where this parts company with a poll. A poll is one question and two
 * buttons; a form is a page of them, and answering it is a thing somebody sits
 * down to do rather than clicks past.
 *
 * The address is also what makes the card work: PresentMessage recognises this
 * route's shape in a message body and draws the card from it, so the link in
 * the channel and the page it leads to are the same fact.
 */
class FormFillController extends Controller
{
    public function show(Request $request, Workspace $workspace, Form $form, PresentForm $present, SubmitForm $submit): Response
    {
        abort_unless($form->workspace_id === $workspace->id, 404);

        $this->authorize('view', $form);

        $user = $request->user();

        return Inertia::render('forms/fill', [
            'form' => $present->handle($form),
            'blank' => $submit->blankAnswers($form),

            /*
             * Whether this person may send it in right now, and if not, why.
             * Two separate facts because the screen says different things about
             * them: a closed form is a dead end, one they already answered is
             * not necessarily.
             */
            'canSubmit' => $user->can('submit', $form),
            'hasSubmitted' => $form->hasSubmissionFrom($user),

            'workspaceSlug' => $workspace->slug,

            // Nobody is anonymous here, and the screen says so before somebody
            // types rather than after they have sent it.
            'anonymous' => false,
        ]);
    }

    public function store(Request $request, Workspace $workspace, Form $form, SubmitForm $submit): RedirectResponse
    {
        abort_unless($form->workspace_id === $workspace->id, 404);

        $this->authorize('submit', $form);

        $validated = $request->validate($submit->rulesFor($form), $submit->messagesFor($form));

        $submit->handle($form, $request->user(), $validated['answers']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.fill.sent')]);

        return back();
    }
}
