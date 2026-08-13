<?php

use App\Actions\Chat\SendMessage;
use App\Actions\Secrets\CreateSecretRequest;
use App\Actions\Transfers\ClaimDownload;
use App\Enums\SystemRole;
use App\Enums\TransferAudience;
use App\Enums\WorkflowRunStatus;
use App\Features\MessageBoard;
use App\Features\MessageForwarding;
use App\Features\SecretRequests;
use App\Features\Transfers;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\BoardPost;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SecretRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * A workspace with the four quieter features switched on.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function handoverScene(): array
{
    Storage::fake('local');

    $member = User::factory()->create(['name' => 'Sanne']);
    $workspace = workspaceWithMember($member, SystemRole::Admin);

    foreach ([WorkflowsFeature::class, MessageBoard::class, MessageForwarding::class, SecretRequests::class, Transfers::class] as $feature) {
        Feature::for($workspace)->activate($feature);
    }

    return [$member, $workspace, channelWithMember($workspace, $member)];
}

function handoverWorkflow(Workspace $workspace, User $owner, ?string $trigger = null, array $config = []): Workflow
{
    $factory = Workflow::factory()->enabled();

    if ($trigger !== null) {
        $factory = $factory->triggeredBy($trigger, $config);
    }

    return $factory->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
}

function handoverRun(Workflow $workflow): ?WorkflowRun
{
    return WorkflowRun::query()->where('workflow_id', $workflow->id)->latest('id')->first();
}

/*
 * The two triggers: somebody collected what was sent, somebody filled in what
 * was asked. Both carry metadata and never content — see the events, where that
 * is the point rather than an omission.
 */

it('runs when a transfer is collected, without saying what was in it', function () {
    [$member, $workspace, $channel] = handoverScene();

    $workflow = handoverWorkflow($workspace, $member, 'transfer-downloaded');

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $channel->id,
    ])->create();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'title' => 'Bouwtekeningen',
        'audience' => TransferAudience::Everyone,
    ]);

    app(ClaimDownload::class)->handle(Request::create('/t/'.$transfer->token), $transfer);

    $run = handoverRun($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.transfer.title'))->toBe('Bouwtekeningen')
        ->and(data_get($run->context, 'trigger.transfer.downloads'))->toBe(1)
        ->and(data_get($run->context, 'trigger.sender.name'))->toBe('Sanne')
        // Fetched by somebody with no account, which is what "anyone with the
        // link" means: these stay empty rather than guessing.
        ->and(data_get($run->context, 'trigger.user.id'))->toBeNull()
        ->and(data_get($run->context, 'trigger.recipient.id'))->toBeNull();
});

it('runs when a secret request is filled in, and counts what is left', function () {
    [$member, $workspace, $channel] = handoverScene();

    $workflow = handoverWorkflow($workspace, $member, 'secret-request-answered');

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $channel->id,
    ])->create();

    $request = app(CreateSecretRequest::class)->handle(
        channel: $channel,
        requester: $member,
        title: 'Toegang webshop',
        keys: ['Gebruikersnaam', 'Wachtwoord'],
        validForDays: 14,
    );

    $keys = $request->keys()->orderBy('id')->get();

    $this->actingAs($member)
        ->post(route('secrets.fill', $request), [
            'values' => [
                (string) $keys[0]->id => 'iets versleutelds',
            ],
        ])
        ->assertRedirect();

    $run = handoverRun($workflow);

    expect($run)->not->toBeNull()
        ->and(data_get($run->context, 'trigger.request.title'))->toBe('Toegang webshop')
        ->and(data_get($run->context, 'trigger.request.answered'))->toBe(1)
        // One of two filled in, so the handover is not done — which is the only
        // question worth asking about a request.
        ->and(data_get($run->context, 'trigger.request.outstanding'))->toBe(1)
        ->and(data_get($run->context, 'trigger.request.is_complete'))->toBeFalse()
        ->and(data_get($run->context, 'trigger.requester.name'))->toBe('Sanne');
});

/*
 * The four actions.
 */

it('puts a notice on the board', function () {
    [$member, $workspace] = handoverScene();

    $run = runStep(handoverWorkflow($workspace, $member), 'post-to-board', [
        'title' => 'Storing verholpen',
        'body' => 'Alles doet het weer sinds {{ trigger.tijd }}.',
    ], ['trigger' => ['tijd' => '14:00']]);

    $post = BoardPost::query()->firstOrFail();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($post->title)->toBe('Storing verholpen')
        ->and($post->body)->toBe('Alles doet het weer sinds 14:00.')
        ->and($post->user_id)->toBe($member->id);
});

it('refuses a board notice with nothing in it', function () {
    [$member, $workspace] = handoverScene();

    $run = runStep(handoverWorkflow($workspace, $member), 'post-to-board', [
        'title' => 'Kop',
        'body' => '{{ trigger.leeg }}',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(BoardPost::query()->count())->toBe(0);
});

/**
 * Forwarding keeps who wrote it, which is the difference from writing a new
 * message with the same words in it.
 */
it('forwards the message the trigger was about', function () {
    [$member, $workspace, $channel] = handoverScene();

    $elsewhere = channelWithMember($workspace, $member);

    $original = app(SendMessage::class)->handle(
        channel: $channel,
        author: $member,
        body: 'De lift doet het niet.',
    );

    $run = runStep(handoverWorkflow($workspace, $member), 'forward-message', [
        'channel_id' => $elsewhere->id,
        'note' => 'Ter info:',
    ], ['trigger' => ['message' => ['id' => $original->id]]]);

    $forwarded = Message::query()->where('channel_id', $elsewhere->id)->latest('id')->firstOrFail();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($forwarded->body)->toContain('De lift doet het niet.')
        ->and($forwarded->body)->toContain('Ter info:')
        ->and(data_get($run->context, 'steps.0.channel.id'))->toBe($elsewhere->id);
});

it('asks for credentials and hands over the link, never the answers', function () {
    [$member, $workspace, $channel] = handoverScene();

    $run = runStep(handoverWorkflow($workspace, $member), 'create-secret-request', [
        'channel_id' => $channel->id,
        'title' => 'Toegang voor {{ trigger.klant }}',
        'keys' => ['Gebruikersnaam', 'Wachtwoord', 'Tweestapscode'],
        'valid_for_days' => 7,
    ], ['trigger' => ['klant' => 'Jansen']]);

    $request = SecretRequest::query()->firstOrFail();

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and($request->title)->toBe('Toegang voor Jansen')
        ->and($request->keys()->count())->toBe(3)
        ->and($request->expires_at->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and(data_get($run->context, 'steps.0.request.url'))->toContain((string) $request->id);
});

it('refuses a request with nothing to ask for', function () {
    [$member, $workspace, $channel] = handoverScene();

    $run = runStep(handoverWorkflow($workspace, $member), 'create-secret-request', [
        'channel_id' => $channel->id,
        'title' => 'Toegang',
        'keys' => [],
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(SecretRequest::query()->count())->toBe(0);
});

it('stays out of a workspace that switched the feature off', function () {
    [$member, $workspace] = handoverScene();

    Feature::for($workspace)->deactivate(MessageBoard::class);

    $run = runStep(handoverWorkflow($workspace, $member), 'post-to-board', [
        'title' => 'Kop',
        'body' => 'Tekst',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Failed)
        ->and(BoardPost::query()->count())->toBe(0);
});
