<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SearchMessages;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchMessages $searchMessages,
    ) {}

    /**
     * Full-text search over the workspace, scoped to the channels the member is
     * allowed to read.
     *
     * The query itself lives in SearchMessages, shared with the MCP tool that
     * asks the same question: two searches that ought to agree would drift, and
     * the blocklist is where drifting is dangerous.
     */
    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        abort_unless($workspace->hasMember($user), 403);

        /*
         * The channel arrives as its own parameter rather than inside the
         * query. What somebody typed — "in:algemeen" — is a convenience the
         * field offers them, not a protocol: the frontend takes it apart and
         * says plainly which channel it means, so nothing here has to parse
         * user text into an authorisation decision.
         *
         * Looked up by name within this workspace, and left null when it does
         * not resolve. SearchMessages already ignores a channel the member
         * cannot read, so an invented name searches everything they may see
         * rather than refusing.
         */
        $channel = $request->filled('in')
            ? $workspace->channels()
                ->whereRaw('lower(name) = ?', [mb_strtolower($request->string('in')->value())])
                ->first()
            : null;

        /*
         * The author, by handle, and only among members of this workspace.
         * Unlike the channel, a name that resolves to nobody stops the search:
         * "from:fena" turning into everything Fenna's colleagues wrote is a
         * worse answer than nothing, because it looks like a result.
         */
        $from = $request->filled('from')
            ? $workspace->members()
                ->whereRaw('lower(username) = ?', [mb_strtolower($request->string('from')->value())])
                ->first()
            : null;

        if ($request->filled('from') && $from === null) {
            return response()->json(['results' => []]);
        }

        return response()->json([
            'results' => $this->searchMessages->handle(
                $workspace,
                $user,
                $request->string('q')->value(),
                $channel,
                $from,
            ),
        ]);
    }
}
