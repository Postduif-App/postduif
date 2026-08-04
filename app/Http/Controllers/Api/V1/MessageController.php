<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Chat\SendMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saying something in a channel, over plain HTTP.
 *
 * The third door onto the same act: the chat screen, the MCP tool, and this.
 * All three go through SendMessage, which is the point — a message that skipped
 * it would appear in nobody's sidebar, ping nobody and broadcast to nobody.
 * Stored, and practically unsaid.
 *
 * The message is ordinary in every way. It carries the member's name, they can
 * edit and delete it, and nothing marks it as having come from a script. That
 * is deliberate: they asked for it to be sent, and a badge would be the
 * application second-guessing its own member.
 */
class MessageController extends Controller
{
    public function store(Request $request, SendMessage $sendMessage): MessageResource
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'channel_id' => ['required', 'integer'],

            // The same ceiling the chat screen has. Attachments are not here:
            // sending a file is a multipart upload with a workspace's own rules
            // about size and type, and half of that is worse than none.
            'body' => ['required', 'string', 'max:4000'],

            /*
             * A reply goes into an existing thread. Checked against the channel
             * rather than on its own, so an id from a conversation this member
             * cannot see is not a way to hang a message under it.
             */
            'parent_id' => [
                'nullable',
                'string',
                'ulid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $request->integer('channel_id'))
                    ->whereNull('parent_id'),
            ],
        ]);

        $channel = $this->channelFor($user, (int) $validated['channel_id']);

        /*
         * Posting and replying are different rights: a channel only admins may
         * post in still lets everybody answer in a thread. The web request asks
         * the same question the same way.
         */
        $ability = ($validated['parent_id'] ?? null) === null ? 'post' : 'reply';

        abort_unless($user->can($ability, $channel), 403, __('chat.api.may_not_post'));

        $message = $sendMessage->handle(
            channel: $channel,
            author: $user,
            body: trim($validated['body']),
            parentId: $validated['parent_id'] ?? null,
        );

        return new MessageResource($message);
    }

    /**
     * The channel this token may write in, or a flat no.
     *
     * One answer for three different situations — no such channel, not this
     * member's, and a workspace that does not let tokens in — and that is the
     * whole reason this is a method. Telling them apart would let a caller walk
     * the ids to find out which channels exist and where this person is.
     *
     * The AI-access question is asked here rather than left to the MCP server,
     * because a workspace that switched it off has said what it means: nothing
     * carrying a token joins in here. A setting that only guards one of the two
     * doors is a setting somebody can walk around by changing the URL.
     */
    private function channelFor(User $user, int $id): Channel
    {
        $channel = Channel::query()->visibleTo($user)->whereKey($id)->first();

        abort_if(
            $channel === null || ! $user->workspacesOpenToAi()->contains('id', $channel->workspace_id),
            404,
            __('chat.api.no_channel'),
        );

        abort_if($channel->archived_at !== null, 422, __('chat.channel_archived'));

        return $channel;
    }
}
