<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CreateChannel;
use App\Enums\ChannelType;
use App\Http\Requests\StoreChannelRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function store(
        StoreChannelRequest $request,
        Workspace $workspace,
        CreateChannel $createChannel,
    ): RedirectResponse {
        $channel = $createChannel->handle(
            workspace: $workspace,
            creator: $request->user(),
            name: $request->string('name')->value(),
            type: ChannelType::from($request->string('type')->value()),
            topic: $request->string('topic')->trim()->value() ?: null,
        );

        return redirect()->route('chat.show', [$workspace, $channel]);
    }

    /**
     * Reading a public channel is open to the whole workspace; posting in it
     * means joining first. This is that step.
     */
    public function join(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('join', $channel);

        $channel->members()->syncWithoutDetaching([
            $request->user()->id => ['joined_at' => now()],
        ]);

        return back();
    }
}
