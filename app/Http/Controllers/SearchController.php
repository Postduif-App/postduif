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

        return response()->json([
            'results' => $this->searchMessages->handle(
                $workspace,
                $user,
                $request->string('q')->value(),
            ),
        ]);
    }
}
