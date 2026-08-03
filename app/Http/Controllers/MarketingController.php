<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\BuildFeatureInventory;
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
    public function home(BuildFeatureInventory $inventory): Response
    {
        return Inertia::render('marketing/home', [
            'features' => $inventory->handle(),
            'roles' => $inventory->roles(),
        ]);
    }
}
