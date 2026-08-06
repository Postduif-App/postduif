<?php

use App\Actions\Chat\AddChannelMembers;
use App\Actions\Chat\SendMessage;
use App\Actions\Chat\ToggleReaction;
use App\Enums\SystemRole;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A workspace with workflows on, a beheerder, and two channels — the second one
 * is what makes "only in this channel" testable at all.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel}
 */
function triggerScene(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    return [
        $owner,
        $workspace,
        channelWithMember($workspace, $owner),
        channelWithMember($workspace, $owner),
    ];
}

it('starts a workflow when somebody says one of its words', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);

    app(SendMessage::class)->handle($channel, $owner, 'Er is hier een storing gemeld');

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.keyword'))->toBe('storing')
        ->and(data_get($run->context, 'trigger.user.name'))->toBe($owner->name)
        ->and(data_get($run->context, 'trigger.channel.id'))->toBe($channel->id)
        ->and(data_get($run->context, 'trigger.message.text'))->toBe('Er is hier een storing gemeld');
});

it('does not fire on a word that only happens to sit inside another', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);

    app(SendMessage::class)->handle($channel, $owner, 'Bezig met het restoring van de backup');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('minds no capitals when it looks for a word', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);

    app(SendMessage::class)->handle($channel, $owner, 'STORING!');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('stays out of the channels a keyword workflow was not pointed at', function () {
    [$owner, , $channel, $elsewhere] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', [
        'keywords' => ['storing'],
        'channel_id' => $channel->id,
    ], $channel);

    app(SendMessage::class)->handle($elsewhere, $owner, 'Er is een storing');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);

    app(SendMessage::class)->handle($channel, $owner, 'Er is een storing');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('watches the whole workspace when no channel was named', function () {
    [$owner, , $channel, $elsewhere] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);

    app(SendMessage::class)->handle($elsewhere, $owner, 'Er is een storing');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('leaves a switched-off workflow alone, however well the word matches', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);
    $workflow->disable();

    app(SendMessage::class)->handle($channel, $owner, 'Er is een storing');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('does not reach into another workspace', function () {
    [$owner, , $channel] = triggerScene();
    [$otherOwner, , $otherChannel] = triggerScene();

    $mine = listeningWorkflow($owner, 'message-keyword', ['keywords' => ['storing']], $channel);

    app(SendMessage::class)->handle($otherChannel, $otherOwner, 'Er is een storing');

    expect(WorkflowRun::query()->where('workflow_id', $mine->id)->count())->toBe(0);
});

it('starts a workflow when somebody joins a channel', function () {
    [$owner, $workspace, $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'channel-join', ['channel_id' => $channel->id], $channel);

    $newcomer = User::factory()->create();
    joinWorkspace($workspace, $newcomer, SystemRole::Member);

    app(AddChannelMembers::class)->handle($channel, [$newcomer->id]);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.user.id'))->toBe($newcomer->id)
        ->and(data_get($run->context, 'trigger.channel.id'))->toBe($channel->id);
});

it('does not welcome somebody who was already in the channel', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'channel-join', ['channel_id' => $channel->id], $channel);

    // The owner is a member already, so this changes nothing.
    app(AddChannelMembers::class)->handle($channel, [$owner->id]);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('starts a workflow when its emoji goes on a message', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'reaction', ['emoji' => '🚨'], $channel);

    $author = User::factory()->create();
    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    app(ToggleReaction::class)->handle($message, $owner, '🚨');

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.emoji'))->toBe('🚨')
        // The one who reacted and the one who wrote it, kept apart.
        ->and(data_get($run->context, 'trigger.user.id'))->toBe($owner->id)
        ->and(data_get($run->context, 'trigger.author.id'))->toBe($author->id);
});

it('ignores an emoji it was not listening for, and taking one back off', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = listeningWorkflow($owner, 'reaction', ['emoji' => '🚨'], $channel);

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    app(ToggleReaction::class)->handle($message, $owner, '👍');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);

    // On, then off again: one run, not two.
    app(ToggleReaction::class)->handle($message, $owner, '🚨');
    app(ToggleReaction::class)->handle($message, $owner, '🚨');

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('does not go round forever when a workflow says the word it listens for', function () {
    [$owner, , $channel] = triggerScene();

    $workflow = Workflow::factory()->enabled()->triggeredBy('message-keyword', [
        'keywords' => ['storing'],
    ])->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
        'name' => 'Echoput',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Er is een storing, zeggen wij ook.',
    ])->create();

    app(SendMessage::class)->handle($channel, $owner, 'Er is een storing');

    /*
     * Three: the one the message started, and the two its own echoes managed
     * before the depth guard closed the door. Without it this would not have
     * come back at all.
     */
    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(3);
});
