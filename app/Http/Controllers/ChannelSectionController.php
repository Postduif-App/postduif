<?php

namespace App\Http\Controllers;

use App\Models\ChannelSection;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The groups somebody made in their own sidebar.
 *
 * Everything here belongs to the member asking, so being in the workspace is
 * the whole of the permission — there is nothing another member could see or
 * lose. Every lookup is scoped by user_id rather than checked afterwards: a
 * section is addressed by id, and an id from somebody else must not resolve.
 */
class ChannelSectionController extends Controller
{
    /** Enough to organise with, few enough that the sidebar stays a sidebar. */
    private const MAX_SECTIONS = 20;

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $this->member($request, $workspace);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                // Unique per member, not globally: two people may both have a
                // section called "Klanten", and neither is the other's.
                Rule::unique('channel_sections', 'name')
                    ->where('user_id', $user)
                    ->where('workspace_id', $workspace->id),
            ],
        ], [
            'name.unique' => __('requests.section.name_taken'),
        ]);

        abort_if(
            ChannelSection::query()
                ->where('user_id', $user)
                ->where('workspace_id', $workspace->id)
                ->count() >= self::MAX_SECTIONS,
            422,
            __('chat.too_many_sections', ['count' => self::MAX_SECTIONS]),
        );

        ChannelSection::create([
            'user_id' => $user,
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            // At the end, where a new group belongs: inserting at the top would
            // move everything somebody already arranged.
            'position' => ChannelSection::query()
                ->where('user_id', $user)
                ->where('workspace_id', $workspace->id)
                ->max('position') + 1,
        ]);

        return back();
    }

    /**
     * Put a channel in a group, or take it out.
     *
     * One endpoint for both, because it is one question with a nullable answer:
     * "which group is this in?" — and "none" is a valid answer rather than a
     * separate action.
     */
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $this->member($request, $workspace);

        $validated = $request->validate([
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
            'section_id' => [
                'nullable',
                'integer',
                // Scoped to this member: a section id from a colleague simply
                // does not exist as far as this request is concerned.
                Rule::exists('channel_sections', 'id')
                    ->where('user_id', $user)
                    ->where('workspace_id', $workspace->id),
            ],
        ]);

        $sections = ChannelSection::query()
            ->where('user_id', $user)
            ->where('workspace_id', $workspace->id)
            ->get();

        // Out of whatever it was in first: a channel sits in at most one group,
        // and moving it is taking it out and putting it back.
        foreach ($sections as $section) {
            $section->channels()->detach($validated['channel_id']);
        }

        if (isset($validated['section_id'])) {
            $sections->firstWhere('id', $validated['section_id'])
                ?->channels()
                ->attach($validated['channel_id'], [
                    'position' => 0,
                ]);
        }

        return back();
    }

    /**
     * Give a group a different name.
     *
     * Its own endpoint rather than a branch in update(), which answers "which
     * group is this channel in" and takes no section in its path. Two questions
     * with different shapes and different failure modes: this one can collide
     * with a name the member already used, and that one cannot.
     */
    public function rename(Request $request, Workspace $workspace, ChannelSection $section): RedirectResponse
    {
        $user = $this->member($request, $workspace);

        abort_unless(
            $section->user_id === $user && $section->workspace_id === $workspace->id,
            404,
        );

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                // Same rule as creating one, minus itself: saving a group under
                // the name it already has should not be an error.
                Rule::unique('channel_sections', 'name')
                    ->where('user_id', $user)
                    ->where('workspace_id', $workspace->id)
                    ->ignore($section->id),
            ],
        ], [
            'name.unique' => __('requests.section.name_taken'),
        ]);

        $section->forceFill(['name' => $validated['name']])->save();

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, ChannelSection $section): RedirectResponse
    {
        $user = $this->member($request, $workspace);

        abort_unless(
            $section->user_id === $user && $section->workspace_id === $workspace->id,
            404,
        );

        // The channels come back to the ordinary list rather than disappearing
        // with the group: a section is a way of arranging them, not a place
        // they live.
        $section->delete();

        return back();
    }

    private function member(Request $request, Workspace $workspace): int
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403);

        return $user->id;
    }
}
