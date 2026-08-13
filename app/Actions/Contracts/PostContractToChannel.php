<?php

namespace App\Actions\Contracts;

use App\Actions\Chat\SendMessage;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;

/**
 * Put a contract in a channel.
 *
 * An ordinary message holding the contract's link, exactly as a poll, a
 * transfer, a form and a secret request are announced. What makes it readable
 * is the card PresentMessage draws when it recognises the address; take the
 * card away and a member still has a link that works, which is what makes this
 * cheap to do more than once.
 *
 * More than once is the point: the same contract may hang in the sales channel
 * and in the one the finance people read, and each of those is a message rather
 * than a copy of anything.
 *
 * Note what this is not. It does not invite anybody to sign — the signers were
 * named when the contract went out and hold links of their own. This is the
 * colleagues' view: here is what we sent, and here is how far it has got.
 */
class PostContractToChannel
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    /**
     * @param  Workflow|null  $workflow  The workflow putting it there, where one
     *                                   is. The poster still decides whether it
     *                                   may be posted at all; the bot's name is
     *                                   what goes on the message, because nobody
     *                                   walked into this channel to share it.
     */
    public function handle(
        Contract $contract,
        Channel $channel,
        User $poster,
        ?Workflow $workflow = null,
    ): Message {
        return $this->sendMessage->fromMemberOrWorkflow(
            channel: $channel,
            member: $poster,
            body: trim($contract->title.' '.route('chat.contracts.show', [
                $channel->workspace->slug,
                $contract->id,
            ])),
            workflow: $workflow,
        );
    }
}
