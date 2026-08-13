<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTokenWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractTemplateResource;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Which templates this token can send.
 *
 * Here for the same reason the channel list is: the send call needs an id and
 * there is nowhere else to get one. A template is made in the workspace by
 * somebody with the document in front of them, and the system that will be
 * sending it has never seen that screen.
 *
 * It also answers the question that comes just before "mag ik dit versturen".
 * A template that nobody finished preparing will be refused by the send call,
 * and a caller that can see readyToSend beforehand can say so in its own
 * interface instead of discovering it in a 422.
 */
class ContractTemplateController extends Controller
{
    use ResolvesTokenWorkspace;

    /**
     * Enough to choose from. A workspace with more templates than this has an
     * organisational problem that a longer list would not solve.
     */
    private const LIMIT = 100;

    public function index(Request $request): AnonymousResourceCollection
    {
        $workspace = $this->workspaceFor($request);

        $templates = Contract::query()
            ->templates()
            ->where('workspace_id', $workspace->id)

            /*
             * The boxes and the one signer come along, because the resource
             * reads both for every row: how many parties there are, whether
             * the author signed, and what can be filled in. Lazy loading is
             * switched off in this application, so this is not a saving but
             * the difference between a list and an exception.
             */
            ->with(['fields', 'signers'])
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->get();

        return ContractTemplateResource::collection($templates);
    }
}
