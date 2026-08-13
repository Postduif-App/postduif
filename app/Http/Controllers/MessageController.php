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
        $this->channelIsReachable($workspace, $channel);

        $sendMessage->handle(
            channel: $channel,
            author: $request->user(),
            body: $request->string('body')->trim()->value(),
            parentId: $request->input('parent_id'),
            id: $request->string('id')->value(),
            quotedId: $request->input('quoted_message_id'),
            // A message may be nothing but a file, so an empty list is normal
            // here rather than a sign that something went missing.
            attachments: $request->file('attachments', []),
        );

        return back();
    }
}
