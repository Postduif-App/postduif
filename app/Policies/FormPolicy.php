<?php

namespace App\Policies;

use App\Enums\WorkspaceAbility;
use App\Models\Form;
use App\Models\User;

/**
 * Who may do what with one particular form.
 *
 * The line running through all of it: the author owns their form, and whoever
 * manages the workspace can reach any of them. What does *not* follow from
 * either is handing it to the outside world — that is a right of its own, asked
 * for separately in share().
 *
 * Filling one in is deliberately the loosest question here, and answering it
 * over the public link is not asked of this policy at all: that visitor has no
 * account to judge. There the token is the whole permission, which is why
 * sharing is guarded as tightly as it is.
 */
class FormPolicy
{
    /**
     * Whether somebody may see the form to fill it in.
     *
     * Every member of the workspace, guests included. A form is put in a
     * channel or handed over as a link, and a guest who was invited into the
     * channel it lives in is exactly the person some forms are for.
     */
    public function view(User $user, Form $form): bool
    {
        return $form->workspace->hasMember($user);
    }

    /**
     * Whether they may send it in right now.
     *
     * Three things and all of them about the form rather than the person: it
     * has to be open, it has to have questions, and it has to still be taking
     * answers from somebody who already sent one.
     */
    public function submit(User $user, Form $form): bool
    {
        if (! $this->view($user, $form) || ! $form->acceptsAnswers()) {
            return false;
        }

        return $form->allows_multiple_submissions || ! $form->hasSubmissionFrom($user);
    }

    /**
     * Whether they may change the questions.
     *
     * The author, or whoever runs the workspace. Note what is not here: holding
     * CreateForms lets somebody make their own, not edit a colleague's.
     */
    public function update(User $user, Form $form): bool
    {
        if ($form->created_by === $user->id) {
            return true;
        }

        return $form->workspace->allows($user, WorkspaceAbility::ManageWorkspace);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    /**
     * Whether they may read what came back.
     *
     * The same people who may edit it. Answers are the reason somebody filled
     * the thing in for a named colleague, and widening this to "anybody in the
     * channel" would turn a holiday request into a notice board.
     */
    public function viewAnswers(User $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    /**
     * Whether they may put it in a channel.
     *
     * Being allowed to edit the form is not enough on its own — posting it is
     * posting a message, so the channel's own rule is asked as well, at the
     * point where the channel is known.
     */
    public function post(User $user, Form $form): bool
    {
        return $this->update($user, $form) && $form->acceptsAnswers();
    }

    /**
     * Whether they may hand it to the world, or take the link back.
     *
     * Its own right on top of update(), and the one place in this policy where
     * being the author is not enough. See WorkspaceAbility::ShareFormsPublicly
     * for why the two are apart.
     */
    public function share(User $user, Form $form): bool
    {
        return $this->update($user, $form)
            && $form->workspace->allows($user, WorkspaceAbility::ShareFormsPublicly);
    }

    /** Stopping a form early, and letting it run again. */
    public function close(User $user, Form $form): bool
    {
        return $this->update($user, $form);
    }

    public function reopen(User $user, Form $form): bool
    {
        return $this->update($user, $form);
    }
}
