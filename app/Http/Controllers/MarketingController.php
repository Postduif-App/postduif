<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\BuildFeatureInventory;
use App\Workflows\WorkflowRegistry;
use Illuminate\Routing\Router;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public face of Postduif, for somebody who has never logged in.
 *
 * Everything factual on these pages comes from BuildFeatureInventory, which
 * reads the application rather than describing it. That is a deliberate
 * constraint and not a convenience: a marketing page written by hand becomes a
 * promise the code has stopped keeping, and nobody notices until a customer
 * does.
 */
class MarketingController extends Controller
{
    public function home(BuildFeatureInventory $inventory, WorkflowRegistry $registry, Router $router): Response
    {
        return Inertia::render('marketing/home', [
            'features' => $inventory->handle(),
            'roles' => $inventory->roles(),

            /*
             * The three that have no feature switch of their own: how a channel
             * is set up, what a workflow can be built from, and what a personal
             * token opens. Together they are most of what somebody is buying,
             * and none of it would appear on a page that only listed the
             * switches.
             */
            'channelSettings' => $inventory->channelSettings(),
            'workflow' => $inventory->workflowVocabulary($registry),
            'token' => $inventory->tokenSurface($router),
        ]);
    }
}
