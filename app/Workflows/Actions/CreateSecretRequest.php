<?php

namespace App\Workflows\Actions;

use App\Actions\Secrets\CreateSecretRequest as AskForSecrets;
use App\Features\SecretRequests;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Ask somebody for credentials, safely.
 *
 * The onboarding step that is otherwise done by mail: a new customer channel,
 * and a form asking for the four things somebody needs to get started — filled
 * in by them, encrypted in their browser, never readable by this application.
 *
 * What goes in the boxes is nobody's business here and the workflow never sees
 * it. What it does see is the link, which is what a following step sends.
 *
 * The keys come out of a words field, so they are written when the workflow is
 * rather than taken from the trigger — the same limitation create-poll has, and
 * for the same reason: a words field takes no variables.
 */
class CreateSecretRequest extends WorkflowAction
{
    use FindsTargets;

    /** A fortnight, unless the step says otherwise: a request that never runs out is a link that never runs out. */
    private const DEFAULT_DAYS = 14;

    public function __construct(private readonly AskForSecrets $ask) {}

    public static function label(): string
    {
        return __('workflows.actions.create-secret-request.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-secret-request.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::text(
                'title',
                __('workflows.actions.create-secret-request.title.label'),
                __('workflows.actions.create-secret-request.title.hint'),
            ),
            WorkflowField::words(
                'keys',
                __('workflows.actions.create-secret-request.keys.label'),
                __('workflows.actions.create-secret-request.keys.hint'),
            ),
            WorkflowField::number(
                'valid_for_days',
                __('workflows.actions.create-secret-request.days.label'),
                __('workflows.actions.create-secret-request.days.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'request.id' => __('workflows.provides.secret.id'),
            'request.title' => __('workflows.provides.secret.title'),
            'request.url' => __('workflows.provides.secret.url'),
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(SecretRequests::class)) {
            throw new RuntimeException(__('workflows.errors.secrets_off'));
        }

        $channel = $this->channel($context);
        $requester = $this->actor($context);

        /*
         * There is no create() on SecretRequestPolicy — the screen leans on
         * reaching the channel at all — so the question asked here is the same
         * one: may the owner say anything in this room. Asking a customer for
         * their credentials from a channel you are not in is not a thing a
         * workflow should be able to do.
         */
        if ($requester->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_ask_secrets', [
                'channel' => (string) $channel->name,
            ]));
        }

        $title = trim((string) $context->setting('title', ''));
        $keys = $this->keys($context);

        if ($title === '' || $keys === []) {
            throw new RuntimeException(__('workflows.errors.empty_secret_request'));
        }

        $request = $this->ask->handle(
            channel: $channel,
            requester: $requester,
            title: $title,
            keys: $keys,
            validForDays: $this->days($context),
            workflow: $context->workflow,
        );

        return [
            'request' => [
                'id' => $request->id,
                'title' => $request->title,
                // The link the answerer follows. It goes in a message a
                // following step writes — this action sends nothing itself.
                'url' => route('secrets.show', $request),
            ],
            'channel' => ['id' => $channel->id],
        ];
    }

    /** @return list<string> */
    private function keys(WorkflowStepContext $context): array
    {
        $keys = array_map(
            fn (mixed $key): string => trim((string) $key),
            (array) $context->setting('keys', []),
        );

        return array_values(array_filter($keys, fn (string $key): bool => $key !== ''));
    }

    private function days(WorkflowStepContext $context): int
    {
        $days = $context->setting('valid_for_days');

        if (blank($days) || ! is_numeric($days)) {
            return self::DEFAULT_DAYS;
        }

        return max(1, min(365, (int) $days));
    }
}
