<?php

namespace App\Http\Controllers;

use App\Actions\Forms\PostFormToChannel;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Putting a form in a channel.
 *
 * Under the channel, the way a poll is posted, because that is the thing being
 * decided: not "which form" — that one is already written — but "which room
 * gets asked". The form's id comes in the body rather than the path for the
 * same reason.
 *
 * Two permissions, both needed. The form's, because posting somebody else's
 * questionnaire under your own name is not something being in the channel
 * should allow; and the channel's, because this is a message and it lands where
 * messages are allowed.
 */
class ChannelFormController extends Controller
{
    public function store(Request $request, Workspace $workspace, Channel $channel, PostFormToChannel $post): RedirectResponse
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $validated = $request->validate([
            'form_id' => [
                'required',
                Rule::exists('forms', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $form = $workspace->forms()->whereKey($validated['form_id'])->firstOrFail();

        $this->authorize('post', $form);
        $this->authorize('post', $channel);

        $post->handle($form, $channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.form.posted')]);

        return back();
    }
}
