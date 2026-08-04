<?php

use App\Actions\Workflows\ResumeWaitingWorkflows;
use App\Actions\Workflows\RunWorkflow;
use App\Enums\ChannelType;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkspaceRole;
use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Features\Workflows as WorkflowsFeature;
use App\Jobs\RunWorkflowJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use App\Workflows\WorkflowRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/**
 * A workspace with workflows switched on, a beheerder who owns them, and a
 * channel that beheerder is in.
 *
 * @return array{0: Workflow, 1: Workspace, 2: User, 3: Channel}
 */
function workflowWithChannel(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $channel = channelWithMember($workspace, $owner);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Storingsmelder',
    ]);

    return [$workflow, $workspace, $owner, $channel];
}

/** Run one step and hand back the run, so a test can read what happened. */
function runStep(Workflow $workflow, string $action, array $config, array $context = []): WorkflowRun
{
    WorkflowStep::factory()->for($workflow)->at(0)->doing($action, $config)->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => $context + ['depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    return $run->fresh();
}

it('posts in a channel as a bot under the workflow name, not as the person who wrote it', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $run = runStep($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Er is een storing.',
    ]);

    $message = $channel->messages()->latest()->first();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($message->body)->toBe('Er is een storing.')
        // A bot message: nobody's name on it, and no webhook behind it either.
        ->and($message->bot_name)->toBe('Storingsmelder')
        ->and($message->user_id)->toBeNull()
        ->and($message->webhook_id)->toBeNull()
        ->and($owner->id)->not->toBe($message->user_id);
});

it('puts what the trigger saw into the message it posts', function () {
    [$workflow, , , $channel] = workflowWithChannel();

    runStep($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Hoi {{ trigger.user.name }}, welkom.',
    ], ['trigger' => ['user' => ['name' => 'Pietje']]]);

    expect($channel->messages()->latest()->first()->body)->toBe('Hoi Pietje, welkom.');
});

it('refuses to post in a channel of another workspace', function () {
    [$workflow] = workflowWithChannel();

    $elsewhere = Channel::factory()->create();

    $run = runStep($workflow, 'send-channel-message', [
        'channel_id' => $elsewhere->id,
        'body' => 'Hallo daar.',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('bestaat niet meer')
        ->and($elsewhere->messages()->count())->toBe(0);
});

it('refuses to post where the owner has since lost their place', function () {
    [$workflow, $workspace, $owner] = workflowWithChannel();

    $closed = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    $run = runStep($workflow, 'send-channel-message', [
        'channel_id' => $closed->id,
        'body' => 'Hallo daar.',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($closed->messages()->count())->toBe(0)
        ->and($owner->can('view', $closed))->toBeFalse();
});

it('says nothing rather than posting an empty message when a variable turned out missing', function () {
    [$workflow, , , $channel] = workflowWithChannel();

    $run = runStep($workflow, 'send-channel-message', [
        'channel_id' => $channel->id,
        'body' => '{{ trigger.message.text }}',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('geen tekst')
        ->and($channel->messages()->count())->toBe(0);
});

it('opens a conversation with somebody and says its piece there', function () {
    [$workflow, $workspace, $owner] = workflowWithChannel();

    $recipient = User::factory()->create();
    $workspace->members()->attach($recipient->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $run = runStep($workflow, 'send-direct-message', [
        'user_id' => $recipient->id,
        'body' => 'Je staat vandaag op de lijst.',
    ]);

    $dm = Channel::query()->where('type', ChannelType::Direct)->latest('id')->first();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($dm)->not->toBeNull()
        ->and($dm->messages()->first()->bot_name)->toBe('Storingsmelder')
        ->and($dm->members()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$owner->id, $recipient->id]);
});

it('hangs a reply under the message the trigger was about', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $original = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    runStep($workflow, 'reply-in-thread', ['body' => 'Wij kijken ernaar.'], [
        'trigger' => ['message' => ['id' => $original->id]],
    ]);

    $reply = $channel->messages()->whereNotNull('parent_id')->first();

    expect($reply)->not->toBeNull()
        ->and($reply->parent_id)->toBe($original->id)
        ->and($reply->body)->toBe('Wij kijken ernaar.');
});

it('makes a real thread, not just a message with a parent', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $original = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    $run = runStep($workflow, 'reply-in-thread', ['body' => 'Wij kijken ernaar.'], [
        'trigger' => ['message' => ['id' => $original->id]],
    ]);

    $original->refresh();

    /*
     * The counter on the parent is what draws the "1 antwoord" bar under the
     * message. Without it the reply exists in the database and in no thread
     * anybody can see — which is what happens if parent_id is written on after
     * the message was made instead of going through SendMessage.
     */
    expect($original->reply_count)->toBe(1)
        ->and($original->last_reply_at)->not->toBeNull()
        ->and(data_get($run->context, 'steps.0.thread.id'))->toBe($original->id);
});

it('tells everyone about a thread reply the way any other reply is told', function () {
    Event::fake([MessageSent::class, ChannelActivity::class]);

    [$workflow, , $owner, $channel] = workflowWithChannel();

    $original = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    runStep($workflow, 'reply-in-thread', ['body' => 'Wij kijken ernaar.'], [
        'trigger' => ['message' => ['id' => $original->id]],
    ]);

    // Broadcast with its parent already on it, so a client with the thread open
    // puts it there rather than loose in the channel.
    Event::assertDispatched(
        MessageSent::class,
        fn (MessageSent $event): bool => $event->message->parent_id === $original->id,
    );

    // And announced as a reply, which is what nudges the sidebar.
    Event::assertDispatched(
        ChannelActivity::class,
        fn (ChannelActivity $event): bool => $event->isReply === true,
    );
});

it('keeps a thread one level deep when it answers a reply', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $root = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    $reply = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'parent_id' => $root->id,
    ]);

    runStep($workflow, 'reply-in-thread', ['body' => 'En nog iets.'], [
        'trigger' => ['message' => ['id' => $reply->id]],
    ]);

    $answer = $channel->messages()->where('body', 'En nog iets.')->first();

    // Under the root, not under the reply it was answering.
    expect($answer->parent_id)->toBe($root->id);
});

it('pins and unpins the message the trigger was about', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    runStep($workflow, 'pin-message', [], ['trigger' => ['message' => ['id' => $message->id]]]);

    expect($message->fresh()->isPinned())->toBeTrue()
        ->and($message->fresh()->pinned_by)->toBe($owner->id);

    $second = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
    ]);

    runStep($second, 'unpin-message', [], ['trigger' => ['message' => ['id' => $message->id]]]);

    expect($message->fresh()->isPinned())->toBeFalse();
});

it('adds a reaction and means it every time, rather than toggling it off again', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    $context = ['trigger' => ['message' => ['id' => $message->id]]];

    runStep($workflow, 'add-reaction', ['emoji' => '👀'], $context);

    $again = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
    ]);

    runStep($again, 'add-reaction', ['emoji' => '👀'], $context);

    expect($message->reactions()->where('emoji', '👀')->count())->toBe(1);
});

it('takes off only the owner his own reaction', function () {
    [$workflow, $workspace, $owner, $channel] = workflowWithChannel();

    $somebody = User::factory()->create();
    $workspace->members()->attach($somebody->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    $message->reactions()->create(['user_id' => $owner->id, 'emoji' => '👍']);
    $message->reactions()->create(['user_id' => $somebody->id, 'emoji' => '👍']);

    runStep($workflow, 'remove-reaction', ['emoji' => '👍'], [
        'trigger' => ['message' => ['id' => $message->id]],
    ]);

    expect($message->reactions()->where('emoji', '👍')->pluck('user_id')->all())
        ->toBe([$somebody->id]);
});

it('opens a channel and hands its id to the steps after it', function () {
    [$workflow, $workspace] = workflowWithChannel();

    $run = runStep($workflow, 'create-channel', [
        'name' => 'Klant {{ trigger.user.name }}',
        'topic' => 'Alles over deze klant',
    ], ['trigger' => ['user' => ['name' => 'Jansen']]]);

    $channel = $workspace->channels()->where('name', 'klant-jansen')->first();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($channel)->not->toBeNull()
        ->and($channel->topic)->toBe('Alles over deze klant')
        ->and($channel->type)->toBe(ChannelType::Public)
        ->and(data_get($run->context, 'steps.0.channel.id'))->toBe($channel->id);
});

it('archives a channel and opens it again, and shrugs at being asked twice', function () {
    [$workflow, , $owner, $channel] = workflowWithChannel();

    runStep($workflow, 'archive-channel', ['channel_id' => $channel->id]);

    expect($channel->fresh()->archived_at)->not->toBeNull();

    $again = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
    ]);

    $run = runStep($again, 'archive-channel', ['channel_id' => $channel->id]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded);

    $reopen = Workflow::factory()->enabled()->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
    ]);

    runStep($reopen, 'unarchive-channel', ['channel_id' => $channel->id]);

    expect($channel->fresh()->archived_at)->toBeNull();
});

it('puts somebody in a channel and says whether that changed anything', function () {
    [$workflow, $workspace, $owner, $channel] = workflowWithChannel();

    $newcomer = User::factory()->create();
    $workspace->members()->attach($newcomer->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $run = runStep($workflow, 'add-channel-members', [
        'channel_id' => $channel->id,
        'user_id' => $newcomer->id,
    ]);

    expect($channel->members()->whereKey($newcomer->id)->exists())->toBeTrue()
        ->and(data_get($run->context, 'steps.0.added'))->toBeTrue();

    $second = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);

    $twice = runStep($second, 'add-channel-members', [
        'channel_id' => $channel->id,
        'user_id' => $newcomer->id,
    ]);

    // Already in it, so nothing changed — which a following step can act on.
    expect(data_get($twice->context, 'steps.0.added'))->toBeFalse();
});

it('looks a channel up without changing it', function () {
    [$workflow, , , $channel] = workflowWithChannel();

    $channel->update(['topic' => 'Meldingen']);

    $run = runStep($workflow, 'get-channel-info', ['channel_id' => $channel->id]);

    expect(data_get($run->context, 'steps.0.channel.name'))->toBe($channel->name)
        ->and(data_get($run->context, 'steps.0.channel.topic'))->toBe('Meldingen')
        ->and(data_get($run->context, 'steps.0.channel.members'))->toBe(1)
        ->and(data_get($run->context, 'steps.0.channel.archived'))->toBeFalse();
});

it('puts a run down at a wait rather than holding a worker still', function () {
    [$workflow, , , $channel] = workflowWithChannel();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('delay', ['minutes' => 60])->create();
    $after = WorkflowStep::factory()->for($workflow)->at(1)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Nog steeds open.',
    ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    $run->refresh();

    expect($run->status)->toBe(WorkflowRunStatus::Waiting)
        /*
         * What is left rather than how far it got. Resuming after the wait and
         * not at it — coming back to it would wait again, forever — and written
         * as a plan because where a run stands in a workflow that forks is not
         * a number.
         */
        ->and($run->resume_plan)->toBe([$after->id])
        ->and($run->resume_at->isFuture())->toBeTrue()
        ->and($channel->messages()->count())->toBe(0)
        // The wait gets a line of its own, so the run screen has no silent gap.
        ->and($run->stepRuns)->toHaveCount(1);
});

it('refuses a wait of nothing at all', function () {
    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'delay', ['minutes' => 0]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('minstens een minuut');
});

it('refuses a wait longer than anybody will remember writing', function () {
    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'delay', ['minutes' => 60 * 24 * 40]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and($run->failure_reason)->toContain('vier weken');
});

it('picks a waiting run back up once its moment has passed', function () {
    Queue::fake();

    [$workflow] = workflowWithChannel();

    $due = WorkflowRun::factory()->for($workflow)->waiting()->create(['resume_position' => 1]);
    $notYet = WorkflowRun::factory()->for($workflow)->waiting('+1 hour')->create();

    expect(app(ResumeWaitingWorkflows::class)->handle())->toBe(1);

    Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->runId === $due->id);

    expect($due->fresh()->status)->toBe(WorkflowRunStatus::Running)
        // Cleared, so a run that fails later cannot look due all over again.
        ->and($due->fresh()->resume_at)->toBeNull()
        ->and($notYet->fresh()->status)->toBe(WorkflowRunStatus::Waiting);
});

it('does not hand the same waiting run to two sweeps', function () {
    Queue::fake();

    [$workflow] = workflowWithChannel();

    WorkflowRun::factory()->for($workflow)->waiting()->create();

    $resume = app(ResumeWaitingWorkflows::class);

    expect($resume->handle())->toBe(1)
        ->and($resume->handle())->toBe(0);
});

it('carries on where it left off when the wait is over', function () {
    [$workflow, , , $channel] = workflowWithChannel();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('delay', ['minutes' => 60])->create();
    WorkflowStep::factory()->for($workflow)->at(1)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'De wachttijd zit erop.',
    ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    // As the sweep would leave it, an hour later.
    $run->fresh()->forceFill(['status' => WorkflowRunStatus::Running, 'resume_at' => null])->save();

    app(RunWorkflow::class)->handle($run->fresh());

    expect($channel->messages()->latest()->first()->body)->toBe('De wachttijd zit erop.')
        ->and($run->fresh()->status)->toBe(WorkflowRunStatus::Succeeded);
});

it('gives every action a name and a sentence in both languages', function () {
    $registry = app(WorkflowRegistry::class);

    foreach (['nl', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ($registry->actions() as $key => $action) {
            expect($action::label())->not->toContain('workflows.', "{$key} heeft geen naam in {$locale}")
                ->and($action::description())->not->toContain('workflows.');

            foreach ($action::fields() as $field) {
                expect($field->label)->not->toContain('workflows.');
            }

            foreach ($action::provides() as $path => $what) {
                expect($what)->not->toContain('workflows.', "{$key} beschrijft {$path} niet");
            }
        }
    }
});
