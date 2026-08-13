<?php

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\UpdateDocument;
use App\Actions\Polls\CastVote;
use App\Actions\Polls\CreatePoll;
use App\Actions\Polls\SettlePolls;
use App\Enums\ChannelDocumentPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkflowRunStatus;
use App\Features\Documents;
use App\Features\Polls;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Document;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A workspace that keeps documents, runs polls and runs workflows.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function documentPollScene(): array
{
    $member = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($member, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Documents::class);
    Feature::for($workspace)->activate(Polls::class);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'document_policy' => ChannelDocumentPolicy::Everyone,
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return [$member, $workspace, $channel];
}

/**
 * A workflow with nothing in it, for a single step to be hung on.
 *
 * One per runStep() call, and that matters: the helper adds its step to the
 * workflow it is given, so a second call on the same workflow runs the first
 * step over again — which for an append is a second line nobody asked for.
 */
function stepperWorkflow(Workspace $workspace, User $owner): Workflow
{
    return Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
}

/** A switched-on workflow waiting for one moment, with one harmless step. */
function listeningFor(User $owner, Channel $channel, string $trigger, array $config = []): Workflow
{
    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
        'name' => 'Dienst',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $channel->id,
    ])->create();

    return $workflow;
}

function runOf(Workflow $workflow): ?WorkflowRun
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->latest('id')->first();
}

/*
 * Documents.
 */

it('starts a workflow when a document is begun', function () {
    [$member, , $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'document-created');

    app(CreateDocument::class)->handle($channel, $member, 'Projectnotities');

    $run = runOf($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.document.title'))->toBe('Projectnotities')
        ->and(data_get($run->context, 'trigger.document.number'))->toBe(1)
        ->and(data_get($run->context, 'trigger.actor.name'))->toBe('Sanne')
        ->and(data_get($run->context, 'trigger.channel.id'))->toBe($channel->id);
});

/**
 * Autosave fires DocumentUpdated every few seconds of quiet. Nothing may hang
 * off that — a workflow running on keystrokes is a workflow somebody mutes,
 * taking the useful ones with it.
 */
it('says nothing when a document is merely saved', function () {
    [$member, , $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'document-created');

    $document = app(CreateDocument::class)->handle($channel, $member, 'Projectnotities');

    $before = WorkflowRun::query()->where('workflow_id', $workflow->id)->count();

    app(UpdateDocument::class)->handle(
        document: $document,
        editor: $member,
        expectedVersion: $document->version,
        title: 'Projectnotities',
        body: [],
        bodyText: 'iets',
    );

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe($before);
});

it('still knows which document it was after it was removed', function () {
    [$member, , $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'document-deleted');

    $document = app(CreateDocument::class)->handle($channel, $member, 'Oude notulen');

    app(DeleteDocument::class)->handle($document, $member);

    expect(data_get(runOf($workflow)->context, 'trigger.document.title'))->toBe('Oude notulen');
});

it('begins a document from a step, and writes lines into it', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $run = runStep(stepperWorkflow($workspace, $member), 'create-document', [
        'channel_id' => $channel->id,
        'title' => 'Logboek {{ trigger.jaar }}',
    ], ['trigger' => ['jaar' => '2026']]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded);

    $document = Document::query()->where('workspace_id', $channel->workspace_id)->firstOrFail();

    expect($document->title)->toBe('Logboek 2026');

    $appended = runStep(stepperWorkflow($workspace, $member), 'append-to-document', [
        'document_id' => $document->id,
        'text' => 'Contract verstuurd aan {{ trigger.klant }}',
    ], ['trigger' => ['klant' => 'Jan de Vries']]);

    $document->refresh();

    expect($appended->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($document->body_text)->toContain('Contract verstuurd aan Jan de Vries')
        ->and($document->body)->toHaveCount(1)
        // The version moved, which is what makes the next append read again
        // rather than write over what this one put there.
        ->and($document->version)->toBeGreaterThan(1);

    // And a second line lands under the first rather than replacing it.
    runStep(stepperWorkflow($workspace, $member), 'append-to-document', [
        'document_id' => $document->id,
        'text' => 'Contract getekend',
    ]);

    $document->refresh();

    $orders = collect($document->body)->pluck('meta.order')->sort()->values()->all();

    expect($document->body)->toHaveCount(2)
        ->and($orders)->toBe([0, 1])
        ->and($document->body_text)->toContain('Contract verstuurd')
        ->and($document->body_text)->toContain('Contract getekend');
});

it('refuses to write in a document from another workspace', function () {
    [$member, $workspace] = documentPollScene();

    $workflow = stepperWorkflow($workspace, $member);

    $elsewhere = Workspace::factory()->create();
    $theirs = Document::factory()->create(['workspace_id' => $elsewhere->id]);

    $run = runStep($workflow, 'append-to-document', [
        'document_id' => $theirs->id,
        'text' => 'Hallo',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($theirs->fresh()->body_text)->toBe('');
});

/*
 * Polls.
 */

it('hands a workflow the tally when somebody votes', function () {
    [$member, , $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'poll-voted');

    $poll = app(CreatePoll::class)->handle($channel, $member, 'Kantoor of thuis?', ['Kantoor', 'Thuis']);
    $option = $poll->options()->where('label', 'Kantoor')->firstOrFail();

    app(CastVote::class)->handle($poll, $option, $member);

    $run = runOf($workflow);

    expect(data_get($run->context, 'trigger.vote.ticked'))->toBeTrue()
        ->and(data_get($run->context, 'trigger.option.label'))->toBe('Kantoor')
        ->and(data_get($run->context, 'trigger.poll.vote_count'))->toBe(1)
        ->and(data_get($run->context, 'trigger.poll.voter_count'))->toBe(1)
        // The two numbers that make a threshold a condition rather than a
        // trigger of its own.
        ->and(data_get($run->context, 'trigger.poll.leading_option'))->toBe('Kantoor')
        ->and(data_get($run->context, 'trigger.poll.top_votes'))->toBe(1)
        ->and(data_get($run->context, 'trigger.voter.name'))->toBe('Sanne');
});

/** Taking a vote off changes the count too, and the count is what is watched. */
it('runs when a vote comes off as well as when it goes on', function () {
    [$member, , $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'poll-voted');

    $poll = app(CreatePoll::class)->handle($channel, $member, 'Kantoor of thuis?', ['Kantoor', 'Thuis']);
    $option = $poll->options()->where('label', 'Kantoor')->firstOrFail();

    app(CastVote::class)->handle($poll, $option, $member);
    app(CastVote::class)->handle($poll->refresh(), $option, $member);

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(2)
        ->and(data_get(runOf($workflow)->context, 'trigger.vote.ticked'))->toBeFalse()
        ->and(data_get(runOf($workflow)->context, 'trigger.poll.vote_count'))->toBe(0);
});

/**
 * The threshold the builder does not have as a trigger: a vote plus a rule.
 */
it('lets a condition on the counts do the work of a threshold trigger', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $workflow = Workflow::factory()->enabled()->triggeredBy('poll-voted', [])->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'name' => 'Drempel',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)
        ->doing('send-channel-message', [
            'channel_id' => $channel->id,
            'body' => '{{ trigger.poll.leading_option }} ligt voor.',
        ])
        ->onlyIf([
            'match' => 'all',
            'otherwise' => 'skip',
            'rules' => [['path' => 'trigger.poll.top_votes', 'operator' => 'greater-or-equal', 'value' => '2']],
        ])
        ->create();

    $poll = app(CreatePoll::class)->handle($channel, $member, 'Kantoor of thuis?', ['Kantoor', 'Thuis']);
    $option = $poll->options()->where('label', 'Kantoor')->firstOrFail();

    $second = User::factory()->create();
    joinWorkspace($workspace->refresh(), $second);
    $channel->members()->attach($second->id, ['joined_at' => now()]);

    app(CastVote::class)->handle($poll, $option, $member);

    // One vote: the step is skipped, and the channel has only the poll's own
    // message in it.
    expect($channel->messages()->count())->toBe(1);

    app(CastVote::class)->handle($poll->refresh(), $option, $second);

    expect($channel->messages()->latest('id')->value('body'))->toBe('Kantoor ligt voor.');
});

it('runs when somebody stops a poll, from the button or from a step', function () {
    [$member, $workspace, $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'poll-closed');

    $poll = app(CreatePoll::class)->handle($channel, $member, 'Kantoor of thuis?', ['Kantoor', 'Thuis']);

    $run = runStep(stepperWorkflow($workspace, $member), 'close-poll', ['poll_id' => $poll->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.closed'))->toBeTrue()
        ->and($poll->fresh()->closed_at)->not->toBeNull()
        // And the listening workflow heard it, because the step fires the same
        // event the button in the channel does.
        ->and(runOf($workflow))->not->toBeNull();

    // Closing it again does nothing and does not fail.
    $again = runStep(stepperWorkflow($workspace, $member), 'close-poll', ['poll_id' => $poll->id]);

    expect($again->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($again->context, 'steps.0.closed'))->toBeFalse();
});

it('runs when a poll reaches its own deadline, with nobody having pressed anything', function () {
    [$member, $workspace, $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'poll-closed');

    $poll = app(CreatePoll::class)->handle(
        $channel,
        $member,
        'Kantoor of thuis?',
        ['Kantoor', 'Thuis'],
        closesInHours: 1,
    );

    // Before the moment: nothing has happened, and nothing should have run.
    app(SettlePolls::class)->handle();

    expect(runOf($workflow))->toBeNull();

    $this->travel(2)->hours();

    app(SettlePolls::class)->handle();

    // This was the half that used to be missing: a deadline is compared where
    // the poll is read, so until the sweep existed a poll that ran out at
    // midnight had been closed since midnight without anything running.
    expect(runOf($workflow))->not->toBeNull()
        ->and($poll->fresh()->closed_at)->toBeNull()
        ->and($poll->fresh()->settled_at)->not->toBeNull();
});

it('starts a poll from a step, with the answers written into it', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $workflow = stepperWorkflow($workspace, $member);

    $run = runStep($workflow, 'create-poll', [
        'channel_id' => $channel->id,
        'question' => 'Wie is er maandag op kantoor?',
        'options' => ['Ik', 'Niet ik'],
        'closes_in_hours' => 48,
    ]);

    $poll = Poll::query()->where('channel_id', $channel->id)->firstOrFail();
    $announcement = Message::query()->where('channel_id', $channel->id)->latest('id')->firstOrFail();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($poll->question)->toBe('Wie is er maandag op kantoor?')
        ->and($poll->options()->pluck('label')->all())->toBe(['Ik', 'Niet ik'])
        ->and($poll->closes_at)->not->toBeNull()
        ->and($poll->allows_multiple)->toBeFalse()
        // The question is put by the bot. The poll itself stays the owner's,
        // because a poll needs somebody who may close it.
        ->and($poll->created_by)->toBe($member->id)
        ->and($announcement->user_id)->toBeNull()
        ->and($announcement->bot_name)->toBe($workflow->botName());
});

it('leaves a poll somebody starts themselves in their own name', function () {
    [$member, $workspace, $channel] = documentPollScene();

    app(CreatePoll::class)->handle($channel, $member, 'Wat vinden we?', ['Ja', 'Nee']);

    $announcement = Message::query()->where('channel_id', $channel->id)->latest('id')->firstOrFail();

    expect($announcement->user_id)->toBe($member->id)
        ->and($announcement->bot_name)->toBeNull();
});

it('refuses a poll with only one answer', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $run = runStep(stepperWorkflow($workspace, $member), 'create-poll', [
        'channel_id' => $channel->id,
        'question' => 'Doen we dat?',
        'options' => ['Ja'],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(Poll::query()->count())->toBe(0);
});

it('stays out of a workspace that switched documents or polls off', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $documents = listeningFor($member, $channel, 'document-created');
    $polls = listeningFor($member, $channel, 'poll-created');

    Feature::for($workspace)->deactivate(Documents::class);
    Feature::for($workspace)->deactivate(Polls::class);

    app(CreateDocument::class)->handle($channel, $member, 'Notities');
    app(CreatePoll::class)->handle($channel, $member, 'Wat vinden we?', ['Ja', 'Nee']);

    expect(runOf($documents))->toBeNull()
        ->and(runOf($polls))->toBeNull();
});

it('counts what a multiple-choice poll means by an answer', function () {
    [$member, $workspace, $channel] = documentPollScene();
    $workflow = listeningFor($member, $channel, 'poll-voted');

    $poll = app(CreatePoll::class)->handle(
        $channel, $member, 'Waar heb je zin in?', ['Pizza', 'Sushi'], allowsMultiple: true,
    );

    foreach (PollOption::query()->where('poll_id', $poll->id)->get() as $option) {
        app(CastVote::class)->handle($poll->refresh(), $option, $member);
    }

    // Two votes, one voter — which is the number anybody actually means.
    expect(data_get(runOf($workflow)->context, 'trigger.poll.vote_count'))->toBe(2)
        ->and(data_get(runOf($workflow)->context, 'trigger.poll.voter_count'))->toBe(1);
});

it('reads a poll again, so the tally is the one at the moment of the step', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $poll = app(CreatePoll::class)->handle($channel, $member, 'Kantoor of thuis?', ['Kantoor', 'Thuis']);
    $option = $poll->options()->where('label', 'Thuis')->firstOrFail();

    // What the trigger saw when the poll was still empty.
    $before = ['trigger' => ['poll' => ['id' => $poll->id, 'top_votes' => 0]]];

    app(CastVote::class)->handle($poll, $option, $member);

    $run = runStep(stepperWorkflow($workspace, $member), 'read-poll', ['poll_id' => $poll->id], $before);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.poll.leading_option'))->toBe('Thuis')
        ->and(data_get($run->context, 'steps.0.poll.top_votes'))->toBe(1)
        ->and(data_get($run->context, 'steps.0.poll.voter_count'))->toBe(1)
        ->and(data_get($run->context, 'trigger.poll.top_votes'))->toBe(0);
});

it('reads a document again, and gives back the name it has now', function () {
    [$member, $workspace, $channel] = documentPollScene();

    $document = app(CreateDocument::class)->handle($channel, $member, 'Projectnotities');

    $document->forceFill(['title' => 'Projectnotities 2027'])->save();

    $run = runStep(stepperWorkflow($workspace, $member), 'read-document', [
        'document_id' => $document->id,
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.document.title'))->toBe('Projectnotities 2027')
        ->and(data_get($run->context, 'steps.0.channel.id'))->toBe($channel->id);
});
