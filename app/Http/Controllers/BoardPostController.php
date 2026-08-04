<?php

namespace App\Http\Controllers;

use App\Actions\Board\PostToBoard;
use App\Actions\Board\PresentBoardPost;
use App\Actions\Chat\BuildChatShell;
use App\Http\Requests\StoreBoardPostRequest;
use App\Http\Requests\UpdateBoardPostRequest;
use App\Models\BoardPost;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The prikbord: what the workspace has put up, for everybody in it.
 *
 * Inside the chat shell rather than on a page of its own, the same choice the
 * workspace-wide ticket list and the transfer list make: it needs the same
 * sidebar, the same unread counts and the same live connection, and a second
 * shell is how those three start disagreeing between screens.
 *
 * Nothing here is scoped per channel, which is the difference worth noticing
 * against WorkspaceTicketController. A ticket is as visible as its channel, so
 * that controller narrows to the channels the sidebar shows. A notice belongs
 * to the workspace itself — so there is exactly one question to ask, and
 * BoardPostPolicy asks it: are you a member here who is not a guest.
 *
 * The bound notice is called $boardPost rather than the $post it reads better
 * as, and that is not a style choice: implicit binding matches on the parameter
 * name, so a $post here would not be filled from {board_post} — it would be
 * resolved from the container as an empty model, and every policy asked about it
 * would be asked about nothing at all. Renaming it is a silent 403 waiting to
 * happen.
 */
class BoardPostController extends Controller
{
    public function __construct(
        private readonly BuildChatShell $buildChatShell,
        private readonly PresentBoardPost $presentBoardPost,
        private readonly PostToBoard $postToBoard,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        $this->authorize('viewAny', [BoardPost::class, $workspace]);

        /*
         * Fetched once here rather than per notice inside the presenter: the
         * whole page is one workspace, and asking it for its blocklist thirty
         * times is thirty queries for one answer that cannot have changed in
         * between.
         */
        $blockedWords = $workspace->blocked_words;

        $posts = BoardPost::query()
            ->where('workspace_id', $workspace->id)
            ->with('author')
            ->withCount('comments')
            ->inBoardOrder()
            ->limit(200)
            ->get();

        return Inertia::render('chat/board', [
            ...$this->buildChatShell->handle($workspace, $user),
            'posts' => $posts
                ->map(fn (BoardPost $post): array => $this->presentBoardPost->summary($post, $blockedWords))
                ->all(),
            'post' => $this->openPost($workspace, $request->query('post'), $user, $blockedWords),
            /*
             * Whether the open notice fills the screen instead of sharing it
             * with the list. In the query string beside ?post= rather than in
             * component state, for the reason the whole board reads that way: a
             * long notice is exactly the kind somebody sends to a colleague, and
             * a link that arrives cramped into a panel arrives wrong.
             */
            'fullscreen' => $request->boolean('full'),
            // Asked once for the page: putting something up is a question about
            // the workspace, not about any notice already on it.
            'canPost' => $user->can('create', [BoardPost::class, $workspace]),
        ]);
    }

    /**
     * The notice named by ?post= in the URL, or null.
     *
     * In the query string rather than on a path of its own, the same way an open
     * thread and an open ticket travel: the list stays on screen beside it, and
     * a URL that carries both is one somebody can send to a colleague.
     *
     * A post id from another workspace resolves to null rather than to a 404.
     * It is the same answer the board would give for a notice that has since
     * been taken down, and the two are not worth telling apart out loud — a
     * distinct error would confirm that some other workspace has a notice with
     * that id.
     *
     * @param  array<int, string>  $blockedWords
     * @return array<string, mixed>|null
     */
    private function openPost(Workspace $workspace, ?string $id, User $user, array $blockedWords): ?array
    {
        if ($id === null) {
            return null;
        }

        $post = BoardPost::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($id)
            ->with(['author', 'comments.author', 'reactions.user'])
            ->withCount('comments')
            ->first();

        return $post === null ? null : $this->presentBoardPost->handle($post, $user, $blockedWords);
    }

    public function store(StoreBoardPostRequest $request, Workspace $workspace): RedirectResponse
    {
        $post = $this->postToBoard->handle(
            $workspace,
            $request->user(),
            $request->string('title')->toString(),
            $request->string('body')->toString(),
        );

        // Straight to the notice that was just made, rather than back to the top
        // of the list: somebody who has just written something wants to see it
        // as everybody else will.
        return to_route('chat.board.index', [$workspace, 'post' => $post->id]);
    }

    /**
     * Correcting a notice, or moving it to the top.
     *
     * Two abilities through one endpoint, asked one at a time. Authorising once
     * for the whole request would mean the wider of the two rules decides both —
     * and here the author may not pin while the beheerder may not be the author,
     * so there is no single rule that would be right.
     */
    public function update(UpdateBoardPostRequest $request, Workspace $workspace, BoardPost $boardPost): RedirectResponse
    {
        if ($request->has('pinned')) {
            $this->authorize('pin', $boardPost);

            $this->postToBoard->pin($boardPost, $request->boolean('pinned'));
        }

        if ($request->filled('title')) {
            $this->authorize('update', $boardPost);

            $this->postToBoard->edit(
                $boardPost,
                $request->string('title')->toString(),
                $request->string('body')->toString(),
            );
        }

        return back();
    }

    public function destroy(Workspace $workspace, BoardPost $boardPost): RedirectResponse
    {
        $this->authorize('delete', $boardPost);

        $this->postToBoard->withdraw($boardPost);

        // Back to the board without the ?post= that no longer resolves: leaving
        // it on would draw an empty panel next to the list somebody just
        // returned to.
        return to_route('chat.board.index', $workspace);
    }
}
