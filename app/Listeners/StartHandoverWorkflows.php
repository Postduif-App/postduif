<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\SecretRequestAnswered;
use App\Events\TransferDownloaded;
use App\Events\UploadLinkSubmitted;
use App\Models\SecretRequest;
use App\Models\Transfer;
use App\Models\TransferRecipient;
use App\Models\UploadLinkSubmission;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\Triggers\SecretRequestAnsweredTrigger;
use App\Workflows\Triggers\TransferDownloadedTrigger;
use App\Workflows\Triggers\UploadLinkSubmittedTrigger;

/**
 * Set off the workflows that were waiting for something to be handed over.
 *
 * Three features in one listener because they are the same shape of thing:
 * somebody waiting to hear that the other side collected what was sent, or
 * handed over what was asked for.
 * Neither payload carries any of what was sent — see both events, where that is
 * the point rather than an omission.
 */
class StartHandoverWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleTransferDownloaded(TransferDownloaded $event): void
    {
        $transfer = Transfer::query()->with(['workspace', 'sender'])->find($event->transferId);

        if ($transfer === null) {
            return;
        }

        $recipient = $event->recipientId === null ? null : TransferRecipient::find($event->recipientId);
        $user = $event->userId === null ? null : User::find($event->userId);

        $this->startWorkflows->handle(
            $transfer->workspace,
            TransferDownloadedTrigger::class,
            fn (Workflow $workflow): array => [
                'transfer' => [
                    'id' => $transfer->id,
                    'title' => $transfer->title,
                    'downloads' => $transfer->downloads,
                    'expires_at' => $transfer->expires_at->toIso8601String(),
                ],
                'sender' => ['id' => $transfer->created_by, 'name' => $transfer->sender?->name],
                'recipient' => ['id' => $recipient?->id, 'email' => $recipient?->email],
                'user' => ['id' => $user?->id, 'name' => $user?->name],
            ],
        );
    }

    public function handleUploadLinkSubmitted(UploadLinkSubmitted $event): void
    {
        $submission = UploadLinkSubmission::query()
            ->with(['media', 'uploadLink.workspace', 'uploadLink.owner'])
            ->find($event->submissionId);

        if ($submission === null) {
            return;
        }

        $link = $submission->uploadLink;

        $this->startWorkflows->handle(
            $link->workspace,
            UploadLinkSubmittedTrigger::class,
            fn (Workflow $workflow): array => [
                'upload_link' => [
                    'id' => $link->id,
                    'title' => $link->title,
                    'uploads' => $link->uploads,
                    'expires_at' => $link->expires_at->toIso8601String(),
                ],
                'submission' => [
                    'id' => $submission->id,
                    'files' => $submission->files()->count(),
                    'size' => $submission->size(),
                ],
                'uploader' => ['name' => $submission->name, 'email' => $submission->email],
                'owner' => ['id' => $link->created_by, 'name' => $link->owner?->name],
            ],
        );
    }

    public function handleSecretRequestAnswered(SecretRequestAnswered $event): void
    {
        $request = SecretRequest::query()
            ->with(['channel', 'workspace', 'requester', 'keys.value'])
            ->find($event->secretRequestId);

        if ($request === null) {
            return;
        }

        /*
         * Counted off the loaded rows rather than with withCount, so the two
         * numbers cannot come from different reads — and so the model keeps the
         * properties it declares. A request has a handful of keys; this is not
         * the place to save a query.
         */
        $asked = $request->keys->count();
        $filled = $request->keys->filter(fn ($key): bool => $key->value !== null)->count();

        $user = $event->userId === null ? null : User::find($event->userId);

        $this->startWorkflows->handle(
            $request->workspace,
            SecretRequestAnsweredTrigger::class,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $request) ? [
                'request' => [
                    'id' => $request->id,
                    'title' => $request->title,
                    // How many this submission filled in, and how many are
                    // still open — which together answer the only question
                    // worth asking: is the handover done.
                    'answered' => $event->answered,
                    'outstanding' => max(0, $asked - $filled),
                    'is_complete' => $filled >= $asked,
                ],
                'channel' => ['id' => $request->channel_id, 'name' => $request->channel?->name],
                'requester' => ['id' => $request->created_by, 'name' => $request->requester?->name],
                'user' => ['id' => $user?->id, 'name' => $user?->name],
            ] : null,
        );
    }

    private function matches(Workflow $workflow, SecretRequest $request): bool
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // Loosely, because the id came out of a JSON column where 7 may be "7".
        return blank($channelId)
            || ! ctype_digit((string) $channelId)
            || (int) $channelId === $request->channel_id;
    }
}
