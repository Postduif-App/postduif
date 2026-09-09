<?php

use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Features\Tickets;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\BacklogConnection;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Workflows\Actions\CreateBacklogIssueAction;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

/**
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message, 4: BacklogConnection}
 */
function backlogIssueScene(?int $backlogWorkspaceId = 42): array
{
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member, SystemRole::Admin);
    Feature::for($workspace)->activate(Tickets::class);
    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'ticket_policy' => ChannelTicketPolicy::Everyone,
    ]);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'body' => 'De export knop doet het niet meer sinds gisteren.',
    ]);

    $connection = BacklogConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'backlog_workspace_id' => $backlogWorkspaceId,
    ]);

    return [$member, $workspace, $channel, $message, $connection];
}

function backlogIssueContext(Workflow $workflow, Message $message, array $config): WorkflowStepContext
{
    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => ['trigger' => ['message' => ['id' => $message->id]]],
    ]);

    return new WorkflowStepContext($workflow, $run, $config);
}

it('opens an issue on Backlog and mirrors it as a ticket', function () {
    [$member, $workspace, $channel, $message, $connection] = backlogIssueScene();

    Http::fake([
        $connection->backlog_url.'/oauth/token' => Http::response([
            'access_token' => 'tok_123', 'expires_in' => 3600,
        ]),
        $connection->backlog_url.'/api/v1/workspaces/42/issues' => Http::response([
            'data' => ['id' => 987, 'identifier' => 'ENG-55'],
        ], 201),
    ]);

    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $member->id]);
    $context = backlogIssueContext($workflow, $message, [
        'connection_id' => $connection->id,
        'team' => 'eng',
    ]);

    $result = app(CreateBacklogIssueAction::class)->run($context);

    expect($result['issue']['id'])->toBe(987)
        ->and($result['issue']['identifier'])->toBe('ENG-55')
        ->and($result['issue']['url'])->toBe($connection->backlog_url.'/issues/ENG-55');

    Http::assertSent(fn ($request) => $request->url() === $connection->backlog_url.'/api/v1/workspaces/42/issues'
        && $request['team'] === 'eng'
        && $request['title'] === 'De export knop doet het niet meer sinds gisteren.'
        && $request->hasHeader('Authorization', 'Bearer tok_123'));

    $ticket = Ticket::query()->where('workspace_id', $workspace->id)->sole();

    expect($ticket->channel_id)->toBe($channel->id)
        ->and($ticket->external_source)->toBe('backlog')
        ->and($ticket->external_id)->toBe('987')
        ->and($ticket->external_url)->toBe($connection->backlog_url.'/issues/ENG-55')
        ->and($ticket->opened_by)->toBe($member->id);

    expect($connection->refresh()->consecutive_failures)->toBe(0);
});

it('refuses without failing the connection when Backlog does not know the team', function () {
    [$member, $workspace, , $message, $connection] = backlogIssueScene();

    Http::fake([
        $connection->backlog_url.'/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        $connection->backlog_url.'/api/v1/workspaces/42/issues' => Http::response(['message' => 'Unknown team.'], 422),
    ]);

    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $member->id]);
    $context = backlogIssueContext($workflow, $message, [
        'connection_id' => $connection->id,
        'team' => 'ghost',
    ]);

    expect(fn () => app(CreateBacklogIssueAction::class)->run($context))
        ->toThrow(RuntimeException::class);

    expect(Ticket::query()->count())->toBe(0)
        ->and($connection->refresh()->consecutive_failures)->toBe(0);
});

it('refuses a connection with no Backlog workspace-id before calling out at all', function () {
    [$member, $workspace, , $message, $connection] = backlogIssueScene(backlogWorkspaceId: null);

    Http::fake();

    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $member->id]);
    $context = backlogIssueContext($workflow, $message, [
        'connection_id' => $connection->id,
        'team' => 'eng',
    ]);

    expect(fn () => app(CreateBacklogIssueAction::class)->run($context))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});
