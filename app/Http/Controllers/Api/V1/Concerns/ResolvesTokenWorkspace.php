<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Workspace;
use App\Support\ApiTokenContext;
use Illuminate\Http\Request;

/**
 * Which workspace a contract call is about.
 *
 * The older endpoints in this API never had to ask. They are about the member
 * behind the token — their status, the channels they can reach — and a member
 * is the same person in every workspace they belong to. A contract is not: it
 * belongs to one workspace, is sent under its name, from its mail settings, by
 * somebody that workspace gave the right to.
 *
 * So these endpoints refuse a token that was not tied to one. Falling back to
 * "the workspaces this member belongs to" was the obvious alternative and is
 * the wrong one twice over: it would let a credential that lives in somebody
 * else's deployment script send contracts out of every workspace its owner has
 * ever joined, and it would make the answer to "waar komt dit contract vandaan"
 * depend on a list that changes when they join a new one.
 *
 * The refusal is a 403 with a reason rather than a 404, because unlike the
 * ids in this API there is nothing here to keep from a caller: they hold the
 * token, they know who they are, and what they need to be told is to mint a
 * narrower one.
 */
trait ResolvesTokenWorkspace
{
    /**
     * The workspace this token speaks for, or a refusal that says why not.
     *
     * The feature and the right are asked together through the workspace
     * policy, which is the same gate the upload screen goes through — see
     * WorkspacePolicy::createContract. A workspace with contracts switched off
     * answers 404 there, and it answers 404 here: an API is not the place to
     * find out which features a workspace has turned on.
     *
     * One gate for the whole of this API, reading included. Asking a narrower
     * question of the endpoints that only look was considered and dropped:
     * every one of them exists to follow up a contract this token sent, so a
     * caller who may not send has nothing here to read. Per-contract
     * permission is still asked where it belongs — see ContractPolicy, which
     * decides whether this member may see the row at all.
     */
    private function workspaceFor(Request $request): Workspace
    {
        $workspace = ApiTokenContext::workspace($request);

        abort_if($workspace === null, 403, __('contracts.api.token_without_workspace'));

        abort_unless(
            $request->user()->can('createContract', $workspace),
            404,
            __('contracts.api.no_workspace'),
        );

        return $workspace;
    }
}
