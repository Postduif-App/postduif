<?php

use App\Actions\Workflows\DispatchScheduledWorkflows;
use App\Enums\SystemRole;
use App\Features\Webhooks;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Laravel\Pennant\Feature;

use function Pest\Laravel\post;

/**
 * A switched-on workflow with one harmless step, so a run has something to do.
 *
 * @return array{0: Workflow, 1: User, 2: Channel}
 */
function externallyTriggered(string $trigger, array $config = [], string $timezone = 'Europe/Amsterdam'): array
{
    $owner = User::factory()->create(['timezone' => $timezone]);
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);
    Feature::for($workspace)->activate(Webhooks::class);

    $channel = channelWithMember($workspace, $owner);

    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Melder',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $channel->id,
    ])->create();

    return [$workflow, $owner, $channel];
}

it('sets a workflow off when something posts to its URL', function () {
    [$workflow] = externallyTriggered('webhook');

    $token = $workflow->regenerateWebhookToken();

    post(route('workflows.webhook', $token), ['order' => ['nummer' => 42]])
        ->assertStatus(202)
        ->assertJson(['accepted' => true]);

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.payload.order.nummer'))->toBe(42);
});

it('keeps the last body so somebody can see what a sender sends', function () {
    [$workflow] = externallyTriggered('webhook');

    $token = $workflow->regenerateWebhookToken();

    post(route('workflows.webhook', $token), ['klant' => 'Jansen']);

    expect($workflow->fresh()->webhook_payload)->toBe(['klant' => 'Jansen'])
        ->and($workflow->fresh()->webhook_used_at)->not->toBeNull();
});

it('remembers a body that arrived while the workflow was off', function () {
    [$workflow] = externallyTriggered('webhook');

    $token = $workflow->regenerateWebhookToken();
    $workflow->disable();

    // Accepted, because from outside a switched-off workflow and one whose
    // first condition said no are the same thing.
    post(route('workflows.webhook', $token))->assertStatus(202);

    expect($workflow->fresh()->webhook_payload)->not->toBeNull()
        ->and(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('answers the same nothing to an unknown token as to a workspace that said no', function () {
    [$workflow, $owner] = externallyTriggered('webhook');

    $token = $workflow->regenerateWebhookToken();

    post(route('workflows.webhook', 'wfh_bestaatniet'))->assertNotFound();

    Feature::for($workflow->workspace)->deactivate(Webhooks::class);

    post(route('workflows.webhook', $token))->assertNotFound();
});

it('mints a new URL and kills the old one', function () {
    [$workflow] = externallyTriggered('webhook');

    $first = $workflow->regenerateWebhookToken();
    $second = $workflow->regenerateWebhookToken();

    expect($first)->not->toBe($second)
        ->and($workflow->webhookUrl())->toContain($second);

    post(route('workflows.webhook', $first))->assertNotFound();
    post(route('workflows.webhook', $second))->assertStatus(202);
});

it('never lets the token out through a serialised workflow', function () {
    [$workflow] = externallyTriggered('webhook');

    $workflow->regenerateWebhookToken();

    expect($workflow->fresh()->toArray())
        ->not->toHaveKey('webhook_token')
        ->not->toHaveKey('webhook_token_hash')
        ->not->toHaveKey('webhook_payload');
});

it('starts a daily workflow at the hour on the owner his own clock', function () {
    [$workflow] = externallyTriggered('schedule', ['cadence' => 'daily', 'time' => '09:00']);

    // 07:00 UTC is 09:00 in Amsterdam in August.
    $this->travelTo('2026-08-05 07:00:00');

    expect(app(DispatchScheduledWorkflows::class)->handle())->toBe(1)
        ->and(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('leaves a daily workflow alone at every other minute of the day', function () {
    [$workflow] = externallyTriggered('schedule', ['cadence' => 'daily', 'time' => '09:00']);

    $this->travelTo('2026-08-05 07:01:00');

    expect(app(DispatchScheduledWorkflows::class)->handle())->toBe(0);

    $this->travelTo('2026-08-05 08:00:00');

    expect(app(DispatchScheduledWorkflows::class)->handle())->toBe(0)
        ->and(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('does not run the same moment twice when the sweep comes round again', function () {
    externallyTriggered('schedule', ['cadence' => 'daily', 'time' => '09:00']);

    $this->travelTo('2026-08-05 07:00:00');

    $dispatcher = app(DispatchScheduledWorkflows::class);

    expect($dispatcher->handle())->toBe(1)
        ->and($dispatcher->handle())->toBe(0);
});

it('comes back the next day', function () {
    externallyTriggered('schedule', ['cadence' => 'daily', 'time' => '09:00']);

    $dispatcher = app(DispatchScheduledWorkflows::class);

    $this->travelTo('2026-08-05 07:00:00');
    expect($dispatcher->handle())->toBe(1);

    $this->travelTo('2026-08-06 07:00:00');
    expect($dispatcher->handle())->toBe(1);
});

it('minds the day of the week for a weekly workflow', function () {
    // 2026-08-05 is a Wednesday; this one wants Thursday.
    externallyTriggered('schedule', ['cadence' => 'weekly', 'time' => '09:00', 'weekday' => 4]);

    $dispatcher = app(DispatchScheduledWorkflows::class);

    $this->travelTo('2026-08-05 07:00:00');
    expect($dispatcher->handle())->toBe(0);

    $this->travelTo('2026-08-06 07:00:00');
    expect($dispatcher->handle())->toBe(1);
});

it('runs an hourly workflow once an hour and no more', function () {
    externallyTriggered('schedule', ['cadence' => 'hourly']);

    $dispatcher = app(DispatchScheduledWorkflows::class);

    $this->travelTo('2026-08-05 07:00:00');
    expect($dispatcher->handle())->toBe(1)
        ->and($dispatcher->handle())->toBe(0);

    $this->travelTo('2026-08-05 08:00:00');
    expect($dispatcher->handle())->toBe(1);
});

it('leaves a scheduled workflow whose author is gone where it is', function () {
    [$workflow, $owner] = externallyTriggered('schedule', ['cadence' => 'daily', 'time' => '09:00']);

    $owner->delete();

    $this->travelTo('2026-08-05 07:00:00');

    expect(app(DispatchScheduledWorkflows::class)->handle())->toBe(0);
});

it('falls back to nine in the morning for a time nobody could read', function () {
    externallyTriggered('schedule', ['cadence' => 'daily', 'time' => 'ergens rond negenen']);

    $this->travelTo('2026-08-05 07:00:00');

    expect(app(DispatchScheduledWorkflows::class)->handle())->toBe(1);
});

it('lets an ordinary member set a link workflow off from a message', function () {
    [$workflow, $owner, $channel] = externallyTriggered('link');

    $member = User::factory()->create();
    $channel->workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'body' => 'Kun je hiernaar kijken?',
    ]);

    $this->actingAs($member)
        ->post(route('chat.messages.workflows.start', [
            $channel->workspace, $channel, $message, $workflow,
        ]))
        ->assertRedirect();

    $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->first();

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.message.text'))->toBe('Kun je hiernaar kijken?')
        // The one who pressed it, and the one who wrote it, kept apart.
        ->and(data_get($run->context, 'trigger.user.id'))->toBe($member->id)
        ->and(data_get($run->context, 'trigger.author.id'))->toBe($owner->id);
});

it('hides a workflow that is off, or of the wrong kind, behind the same nothing', function () {
    [$link, $owner, $channel] = externallyTriggered('link');
    $link->disable();

    $keyword = Workflow::factory()->enabled()->triggeredBy('message-keyword', [
        'keywords' => ['storing'],
    ])->create([
        'workspace_id' => $channel->workspace_id,
        'created_by' => $owner->id,
    ]);

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    foreach ([$link, $keyword] as $workflow) {
        $this->actingAs($owner)
            ->post(route('chat.messages.workflows.start', [
                $channel->workspace, $channel, $message, $workflow,
            ]))
            ->assertNotFound();
    }
});

it('refuses somebody who cannot see the message at all', function () {
    [$workflow, $owner, $channel] = externallyTriggered('link');

    $outsider = User::factory()->create();

    $message = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
    ]);

    $this->actingAs($outsider)
        ->post(route('chat.messages.workflows.start', [
            $channel->workspace, $channel, $message, $workflow,
        ]))
        ->assertForbidden();

    expect(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(0);
});
