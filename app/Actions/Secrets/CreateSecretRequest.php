<?php

namespace App\Actions\Secrets;

use App\Actions\Chat\SendMessage;
use App\Models\Channel;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

class CreateSecretRequest
{
    public function __construct(private SendMessage $sendMessage) {}

    /**
     * Ask for a set of values, and say so in the channel.
     *
     * The request and its keys are one thing: a request with no keys asks
     * nothing, and keys without a request are rows nobody will ever answer. The
     * transaction is what keeps either from happening.
     *
     * The message goes out after the transaction commits, for the reason the
     * transfer mails do — a message is not something a rollback can take back,
     * and one pointing at a request that never existed is worse than none.
     *
     * @param  array<int, string>  $keys  The names being asked for, in order.
     * @param  bool  $burnAfterReading  Whether each value goes the moment the
     *                                  requester has read it.
     * @param  Workflow|null  $workflow  The workflow asking, where one is. The
     *                                   request stays the requester's — the
     *                                   answers are for a person to read — but
     *                                   the message announcing it is signed by
     *                                   the bot.
     */
    public function handle(
        Channel $channel,
        User $requester,
        string $title,
        array $keys,
        int $validForDays,
        ?string $description = null,
        bool $burnAfterReading = false,
        ?Workflow $workflow = null,
    ): SecretRequest {
        $request = DB::transaction(function () use (
            $channel,
            $requester,
            $title,
            $keys,
            $validForDays,
            $description,
            $burnAfterReading,
        ): SecretRequest {
            $request = SecretRequest::create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'created_by' => $requester->id,
                'title' => $title,
                'description' => $description,
                'expires_at' => now()->addDays($validForDays),
                'burn_after_reading' => $burnAfterReading,
            ]);

            // Deduplicated here rather than left to the unique index: the same
            // name twice is somebody pasting a list, not an error worth an
            // exception.
            foreach (array_values(array_unique($keys)) as $position => $name) {
                SecretRequestKey::create([
                    'secret_request_id' => $request->id,
                    'name' => $name,
                    'position' => $position,
                ]);
            }

            return $request;
        });

        /*
         * An ordinary message holding the link, exactly as a transfer does.
         * What makes it read as more than a URL is the card PresentMessage
         * draws — so nothing here has to be a special kind of message, and a
         * link somebody pastes by hand works just as well.
         */
        $this->sendMessage->fromMemberOrWorkflow(
            channel: $channel,
            member: $requester,
            body: trim($title.' '.route('secrets.show', $request->id)),
            workflow: $workflow,
        );

        return $request;
    }
}
