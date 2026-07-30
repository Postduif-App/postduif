<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SendMessage;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class MessageController extends Controller
{
    public function store(
        StoreMessageRequest $request,
        Workspace $workspace,
        Channel $channel,
        SendMessage $sendMessage,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $sendMessage->handle(
            channel: $channel,
            author: $request->user(),
            body: $request->string('body')->trim()->value(),
            parentId: $request->input('parent_id'),
            id: $request->string('id')->value(),
        );

        return back();
    }
}
