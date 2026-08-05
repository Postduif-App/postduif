<?php

namespace App\Http\Controllers;

use App\Actions\Forms\PresentForm;
use App\Actions\Forms\SubmitForm;
use App\Features\Forms;
use App\Models\Form;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Filling one in from outside.
 *
 * Outside auth, like picking up a transfer or a sent secret, and for the same
 * reason: the person answering may have no account at all. The token in the
 * address is the whole permission, which is what makes everything around it
 * strict.
 *
 * Three rules hold that together. The lookup is by token and never by id, so
 * there is nothing to enumerate and no way to reach a form that was never
 * shared. A withdrawn link is a 404 rather than a "this was withdrawn" — the
 * old address must stop being evidence that anything is there. And the feature
 * is asked about here by hand, because the middleware that usually does it
 * reads a workspace off the route and this route has none.
 */
class PublicFormController extends Controller
{
    public function show(string $token, PresentForm $present, SubmitForm $submit): Response
    {
        $form = $this->shared($token);

        return Inertia::render('forms/public', [
            'form' => $present->handle($form),
            'blank' => $submit->blankAnswers($form),
            'token' => $token,

            /*
             * Always true here, and said out loud on the page. Somebody typing
             * their reason for asking for leave into a browser deserves to know
             * before they start that no name is going with it.
             */
            'anonymous' => true,
        ]);
    }

    public function store(Request $request, string $token, SubmitForm $submit): RedirectResponse
    {
        $form = $this->shared($token);

        abort_unless($form->acceptsAnswers(), 404);

        $validated = $request->validate($submit->rulesFor($form), $submit->messagesFor($form));

        /*
         * No submitter, even when the visitor happens to be signed in and a
         * member. They came through the public door, and a page that promised
         * "je naam wordt niet meegestuurd" has to keep that promise for
         * everybody who read it. via_link records which door it was.
         */
        $submit->handle($form, null, $validated['answers'], viaLink: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.fill.sent')]);

        return back();
    }

    /**
     * The form behind this token, or nothing at all.
     *
     * Everything that could make the link dead answers with the same 404: no
     * such token, the link withdrawn, the workspace having switched forms off.
     * A stranger holding an old URL learns only that it does not work.
     */
    private function shared(string $token): Form
    {
        $form = Form::query()
            ->with(['fields', 'author:id,name', 'workspace'])
            ->where('share_token', $token)
            ->first();

        abort_if($form === null, 404);
        abort_unless($form->workspace->hasFeature(Forms::class), 404);

        return $form;
    }
}
