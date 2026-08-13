<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\ChannelShareAnswered;
use App\Events\ChannelShareOffered;
use App\Events\ChannelShareRevoked;
use App\Models\ChannelShare;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\ChannelShareAnsweredTrigger;
use App\Workflows\Triggers\ChannelShareOfferedTrigger;
use App\Workflows\Triggers\ChannelShareRevokedTrigger;
use App\Workflows\WorkflowTrigger;

/**
 * Set off the workflows that were waiting on a shared channel.
 *
 * The one decision in this class is which of the two workspaces each trigger
 * belongs to, and it comes out the same way every time: the side being *told*
 * something, never the side doing it. The guest hears the offer and the
 * withdrawal; the host hears the answer. A workspace that heard about its own
 * actions would be a workspace whose beheerder switches these off.
 *
 * Both workspaces are described either way. Which one you are is not something
 * a workflow should have to work out from which paths are filled in.
 */
class StartChannelShareWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleOffered(ChannelShareOffered $event): void
    {
        $this->start($event->shareId, ChannelShareOfferedTrigger::class, guest: true);
    }

    public function handleRevoked(ChannelShareRevoked $event): void
    {
        $this->start($event->shareId, ChannelShareRevokedTrigger::class, guest: true);
    }

    public function handleAnswered(ChannelShareAnswered $event): void
    {
        $this->start(
            $event->shareId,
            ChannelShareAnsweredTrigger::class,
            guest: false,
            extra: ['share' => ['accepted' => $event->accepted]],
            answer: $event->accepted ? 'accepted' : 'declined',
        );
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     * @param  bool  $guest  Whose workflows run: the workspace being offered the channel, or the one that owns it.
     * @param  array<string, mixed>  $extra
     */
    private function start(
        int $shareId,
        string $trigger,
        bool $guest,
        array $extra = [],
        ?string $answer = null,
    ): void {
        $share = ChannelShare::query()
            ->with(['channel.workspace', 'workspace'])
            ->find($shareId);

        if ($share === null) {
            return;
        }

        $context = $this->merge($this->context($share), $extra);

        $this->startWorkflows->handle(
            $guest ? $share->workspace : $share->channel->workspace,
            $trigger,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $answer) ? $context : null,
            $context,
        );
    }

    /**
     * Whether this workflow asked about this answer.
     *
     * "any" is a real option rather than an empty one, and an unset setting is
     * read as it too — a workflow written before the dropdown existed goes on
     * doing what it did.
     */
    private function matches(Workflow $workflow, ?string $answer): bool
    {
        if ($answer === null) {
            return true;
        }

        $wanted = (string) $workflow->triggerSetting('answer', 'any');

        return $wanted === 'any' || $wanted === $answer;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function merge(array $context, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $context[$key] = [...$context[$key] ?? [], ...$value];
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ChannelShare $share): array
    {
        return [
            'share' => [
                'id' => $share->id,
                'can_post' => $share->can_post,
            ],
            'channel' => ['id' => $share->channel_id, 'name' => $share->channel->name],
            // Named host and guest rather than "ours" and "theirs", because
            // which is which depends on where you are standing and the payload
            // cannot know that.
            'host' => $this->workspace($share->channel->workspace),
            'guest' => $this->workspace($share->workspace),
        ];
    }

    /**
     * @return array{id: int|null, name: string|null}
     */
    private function workspace(?Workspace $workspace): array
    {
        return ['id' => $workspace?->id, 'name' => $workspace?->name];
    }
}
