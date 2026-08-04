<?php

namespace App\Actions\Board;

use App\Actions\Chat\CensorBlockedWords;
use App\Models\BoardComment;
use App\Models\BoardPost;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A notice as the board and the panel beside it draw it.
 *
 * Note what it does not have to do, compared with PresentTicket: nothing here
 * marks anybody as a guest, because a guest can never see a board post or write
 * one. The rule lives in BoardPostPolicy and is worth stating in one place
 * only — the moment this presenter started re-deciding it, the two would be
 * free to disagree.
 */
class PresentBoardPost
{
    public function __construct(
        private readonly CensorBlockedWords $censorBlockedWords,
    ) {}

    /**
     * A notice as a row in the list.
     *
     * Without the replies, and with the body cut down to a couple of lines: the
     * list is a scan, and dragging every reply on the board into a payload that
     * draws none of them is how a prikbord with a year of history on it becomes
     * slow to open.
     *
     * @param  array<int, string>  $blockedWords
     * @return array<string, mixed>
     */
    public function summary(BoardPost $post, array $blockedWords): array
    {
        $post->loadMissing('author');

        return [
            'id' => $post->id,
            'title' => $this->censor($post->title, $blockedWords),
            'excerpt' => Str::limit($this->censor($post->body, $blockedWords), 180),
            'author' => $this->person($post->author),
            'pinned' => $post->isPinned(),
            'commentCount' => $post->comments_count ?? $post->comments()->count(),
            'createdAt' => $post->created_at?->toIso8601String(),
            'editedAt' => $post->edited_at?->toIso8601String(),
        ];
    }

    /**
     * A notice with everything the panel beside the list shows: the whole text,
     * and the replies under it.
     *
     * Withdrawn replies are simply absent rather than present as tombstones —
     * the choice the migration explains. What a person may do with each of them
     * travels along per reply, because "your own" is a different answer for
     * every row and the browser must not be the place where that gets worked
     * out.
     *
     * @param  array<int, string>  $blockedWords
     * @return array<string, mixed>
     */
    public function handle(BoardPost $post, User $viewer, array $blockedWords): array
    {
        $post->loadMissing(['author', 'comments.author', 'reactions.user']);

        return [
            ...$this->summary($post, $blockedWords),
            'body' => $this->censor($post->body, $blockedWords),
            'canEdit' => $viewer->can('update', $post),
            'canDelete' => $viewer->can('delete', $post),
            'canPin' => $viewer->can('pin', $post),
            'canComment' => $viewer->can('comment', $post),
            'canReact' => $viewer->can('react', $post),
            'reactions' => $this->reactions($post, $viewer),
            'comments' => $post->comments->map(fn (BoardComment $comment): array => [
                'id' => $comment->id,
                'author' => $this->person($comment->author),
                'body' => $this->censor($comment->body, $blockedWords),
                'createdAt' => $comment->created_at?->toIso8601String(),
                'editedAt' => $comment->edited_at?->toIso8601String(),
                'canEdit' => $viewer->can('update', $comment),
                'canDelete' => $viewer->can('delete', $comment),
            ])->values()->all(),
        ];
    }

    /**
     * The emoji under a notice, one entry per emoji rather than per person.
     *
     * Grouped here rather than in the browser because the page is not given the
     * workspace's member list — the board is not a channel and has no roster —
     * so the names for the tooltip have to be read where the users are. "Jij"
     * is decided here too, for the same reason it is decided in the browser for
     * a message: the answer is different per reader, and this payload is built
     * per reader anyway.
     *
     * @return array<int, array{emoji: string, count: int, mine: bool, names: array<int, string>}>
     */
    private function reactions(BoardPost $post, User $viewer): array
    {
        return $post->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'mine' => $group->contains(fn ($reaction): bool => $reaction->user_id === $viewer->id),
                // Always as many names as the count. A reaction cannot outlive
                // the account behind it — the table cascades, the same way a
                // reply under a notice does — so unlike the notice's own author
                // there is no "Oud-collega" case to carry here.
                'names' => $group
                    ->map(fn ($reaction): string => $reaction->user->name)
                    ->values()
                    ->all(),
            ])
            // Most-reacted first, and the rest in the order they were first
            // used: a row that reorders itself on every click is one nobody can
            // aim at.
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * The person behind a notice, or null once they have left the workspace.
     *
     * Null rather than a placeholder name, so the page decides how to say
     * "iemand die hier weg is" — inventing "Verwijderde gebruiker" here would
     * put a piece of copy in a file nobody translates.
     *
     * @return array{id: int, name: string, avatarUrl: string|null}|null
     */
    private function person(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatarUrl' => $user->avatarUrl(),
        ];
    }

    /**
     * @param  array<int, string>  $words
     */
    private function censor(string $text, array $words): string
    {
        return $this->censorBlockedWords->handle($text, $words);
    }
}
