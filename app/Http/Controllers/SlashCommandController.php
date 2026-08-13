<?php

namespace App\Http\Controllers;

use App\Actions\Chat\NoticeInChannel;
use App\Actions\Workflows\StartWorkflow;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\SlashCommandTrigger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SlashCommandController extends Controller
{
    /**
     * Set a workflow off by typing its command in a channel.
     *
     * The third door to the same room — see MessageWorkflowController for why
     * an ordinary member may start a workflow at all. What is different here is
     * the answer: a receipt only the person who typed it can see, in the
     * channel where they typed it. Slack calls that ephemeral; the point is
     * that "ik heb iets aangezet" is news to exactly one person, and a channel
     * where every command leaves a line is a channel people stop using
     * commands in.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        StartWorkflow $startWorkflow,
        NoticeInChannel $noticeInChannel,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        // Posting rather than viewing, unlike the button in the bar: this is
        // reached from the message field, and somebody who may not say anything
        // here has no business setting things off here either.
        $this->authorize('post', $channel);

        $data = $request->validate([
            'command' => ['required', 'string', 'max:'.SlashCommandTrigger::MAX_LENGTH],
            'arguments' => ['nullable', 'string', 'max:2000'],
        ]);

        $command = SlashCommandTrigger::normalise($data['command']);
        $user = $request->user();

        $workflow = Workflow::query()
            ->listeningFor($workspace, SlashCommandTrigger::key())
            ->get()
            ->first(fn (Workflow $candidate): bool => SlashCommandTrigger::normalise(
                (string) $candidate->triggerSetting('command', ''),
            ) === $command);

        /*
         * A command nobody answers to, or a workspace with workflows switched
         * off. Not an error page: this is a typo in a message field, and the
         * answer to a typo is a line saying so — to the person who made it.
         */
        if ($workflow === null || ! $workspace->hasFeature(WorkflowsFeature::class)) {
            $noticeInChannel->handle(
                $channel,
                $user,
                __('workflows.command.unknown', ['command' => $command]),
                keep: true,
            );

            return back();
        }

        $started = $startWorkflow->handle($workflow, [
            'command' => $command,
            'arguments' => $data['arguments'] ?? '',
            'channel' => ['id' => $channel->id, 'name' => $channel->name],
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);

        $noticeInChannel->handle(
            $channel,
            $user,
            $started === null
                ? __('workflows.link.refused')
                : __('workflows.link.started', ['name' => $workflow->name]),
            authorName: $workflow->botName(),
            // A receipt for something that did not happen stays until it is
            // read; one for something that did is worth ten minutes.
            keep: $started === null,
        );

        return back();
    }
}
