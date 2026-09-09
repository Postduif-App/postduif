<?php

namespace App\Workflows\Actions;

use App\Actions\Tickets\CreateTicket;
use App\Enums\WorkflowRecordType;
use App\Features\Tickets;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\GuardOutboundUrl;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Open a new issue on Backlog, out of a message.
 *
 * The one action in this pair that starts something rather than continuing
 * it — ProcessBacklogWebhookJob and SyncTicketToBacklogJob only ever act on
 * an issue Backlog already knows about. This is how one comes to exist in the
 * first place: a member reacts (ReactionTrigger is the ordinary way in), and
 * the message that reaction landed on becomes the issue's title and
 * description.
 *
 * Opening the issue and mirroring it as a ticket happen in the same run,
 * synchronously, the same way HttpRequest reaches off the machine directly
 * rather than through a queued job — a workflow step is already running on
 * the queue, and a second hop would only add a place for the two writes to
 * disagree about whether they both happened.
 */
class CreateBacklogIssueAction extends WorkflowAction
{
    use FindsTargets;

    private const TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly GuardOutboundUrl $guard,
        private readonly CreateTicket $createTicket,
    ) {}

    public static function label(): string
    {
        return __('workflows.actions.create-backlog-issue.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-backlog-issue.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'connection_id',
                WorkflowRecordType::BacklogConnection,
                __('workflows.actions.create-backlog-issue.connection.label'),
                __('workflows.actions.create-backlog-issue.connection.hint'),
                required: true,
            ),
            WorkflowField::words(
                'team',
                __('workflows.actions.create-backlog-issue.team.label'),
                __('workflows.actions.create-backlog-issue.team.hint'),
            ),
            WorkflowField::text(
                'title',
                __('workflows.actions.create-backlog-issue.title.label'),
                __('workflows.actions.create-backlog-issue.title.hint'),
                required: false,
            ),
            WorkflowField::longText(
                'description',
                __('workflows.actions.create-backlog-issue.description_field.label'),
                __('workflows.actions.create-backlog-issue.description_field.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'issue.id' => __('workflows.provides.backlog_issue.id'),
            'issue.identifier' => __('workflows.provides.backlog_issue.identifier'),
            'issue.url' => __('workflows.provides.backlog_issue.url'),
            'ticket.id' => __('workflows.provides.ticket.id'),
            'ticket.number' => __('workflows.provides.ticket.number'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Tickets::class)) {
            throw new RuntimeException(__('workflows.errors.tickets_off'));
        }

        $connection = $this->backlogConnection($context);

        if (! $connection->canCreateIssues()) {
            throw new RuntimeException(__('workflows.errors.backlog_no_workspace'));
        }

        $team = trim((string) $context->setting('team', ''));

        if ($team === '') {
            throw new RuntimeException(__('workflows.errors.backlog_no_team'));
        }

        // Optional on the field, not on the request: falls back to the
        // message the trigger was about, same as every other action that
        // acts on "the message" without being told which one.
        $message = $this->message($context);

        $title = trim((string) $context->setting('title', ''));

        if ($title === '') {
            $title = Str::limit(trim(strip_tags($message->body)), 120);
        }

        if ($title === '') {
            throw new RuntimeException(__('workflows.errors.empty_issue_title'));
        }

        $description = trim((string) $context->setting('description', ''));

        if ($description === '') {
            $description = trim(strip_tags($message->body));
        }

        $token = $connection->accessToken();

        if ($token === null) {
            $connection->recordFailure();

            throw new RuntimeException(__('workflows.errors.backlog_unreachable'));
        }

        try {
            $baseUrl = $this->guard->handle(rtrim($connection->backlog_url, '/'));
        } catch (RuntimeException $exception) {
            $connection->recordFailure();

            throw $exception;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withoutRedirecting()
                ->post("{$baseUrl}/api/v1/workspaces/{$connection->backlog_workspace_id}/issues", [
                    'team' => $team,
                    'title' => $title,
                    'description' => $description,
                ]);
        } catch (ConnectionException $exception) {
            $connection->recordFailure();

            throw new RuntimeException(__('workflows.errors.backlog_unreachable'));
        }

        if ($response->status() === 422) {
            // Refused rather than failed — Backlog's own answer to a team key
            // it does not recognise. Not recorded against the connection: the
            // connection itself is fine, the step's own setting is wrong, and
            // counting that against FAILURE_LIMIT would eventually switch off
            // a connection whose real fault is a typo in a workflow.
            throw new RuntimeException(__('workflows.errors.backlog_unknown_team', ['team' => $team]));
        }

        if (! $response->successful()) {
            $connection->recordFailure();

            throw new RuntimeException(__('workflows.errors.backlog_refused', ['status' => (string) $response->status()]));
        }

        $connection->recordSuccess();

        $issueId = $response->json('data.id');
        $identifier = $response->json('data.identifier');

        $ticket = $this->createTicket->handle(
            $connection->channel,
            $this->actor($context),
            $title,
            $description,
            source: $message,
        );

        $ticket->forceFill([
            'external_source' => 'backlog',
            'external_id' => (string) $issueId,
            'external_url' => is_string($identifier)
                ? "{$baseUrl}/issues/{$identifier}"
                : null,
        ])->save();

        return [
            'issue' => ['id' => $issueId, 'identifier' => $identifier, 'url' => $ticket->external_url],
            'ticket' => ['id' => $ticket->id, 'number' => $ticket->number],
        ];
    }
}
