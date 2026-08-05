<?php

namespace App\Actions\Forms;

use App\Actions\Chat\SendMessage;
use App\Models\Channel;
use App\Models\Form;
use App\Models\Message;
use App\Models\User;

/**
 * Put a form in a channel.
 *
 * An ordinary message holding the form's link, exactly as a poll, a transfer
 * and a secret request are announced. What makes it readable is the card
 * PresentMessage draws when it recognises the address; strip the card away and
 * a member still has a link that works, which is the property that makes this
 * cheap to do more than once.
 *
 * More than once is the point, in fact: the same form may hang in three
 * channels, and each of those is a message rather than a copy of the form.
 */
class PostFormToChannel
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    public function handle(Form $form, Channel $channel, User $poster): Message
    {
        return $this->sendMessage->handle(
            channel: $channel,
            author: $poster,
            body: trim($form->title.' '.route('chat.forms.show', [
                $channel->workspace->slug,
                $form->id,
            ])),
        );
    }
}
