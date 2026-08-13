<?php

namespace App\Workflows\Actions;

use App\Actions\Board\PostToBoard as WriteOnBoard;
use App\Features\MessageBoard;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put a notice on the workspace board.
 *
 * The board is the one place in this application that is not a channel: it
 * reaches everybody without anybody having to be in a room. So this is the step
 * for the announcement that would otherwise be pasted into four channels — a
 * release, a closure, a change everybody has to know about.
 *
 * In the name of the workflow's owner, like a ticket and unlike a message. A
 * notice everybody sees is a notice somebody is answerable for.
 */
class PostToBoard extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly WriteOnBoard $writeOnBoard) {}

    public static function label(): string
    {
        return __('workflows.actions.post-to-board.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.post-to-board.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text(
                'title',
                __('workflows.actions.post-to-board.title.label'),
                __('workflows.actions.fields.body_hint'),
            ),
            WorkflowField::longText(
                'body',
                __('workflows.actions.fields.body'),
                __('workflows.actions.fields.body_hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'post.id' => __('workflows.provides.board.id'),
            'post.title' => __('workflows.provides.board.title'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(MessageBoard::class)) {
            throw new RuntimeException(__('workflows.errors.board_off'));
        }

        $author = $this->actor($context);
        $title = trim((string) $context->setting('title', ''));
        $body = trim((string) $context->setting('body', ''));

        if ($title === '' || $body === '') {
            throw new RuntimeException(__('workflows.errors.empty_board_post'));
        }

        $post = $this->writeOnBoard->handle($context->workspace(), $author, $title, $body);

        return ['post' => ['id' => $post->id, 'title' => $post->title]];
    }
}
