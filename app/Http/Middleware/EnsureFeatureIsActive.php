<?php

namespace App\Http\Middleware;

use App\Features\WorkspaceFeature;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a switched-off feature from being reached by asking for it directly.
 *
 * On the route group rather than in each controller: seven features across
 * roughly twenty endpoints is exactly the shape of thing where one gets missed,
 * and a feature that is off everywhere except one forgotten endpoint is not off.
 */
class EnsureFeatureIsActive
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $feature = WorkspaceFeature::fromKey($key);

        if ($feature === null) {
            // Nobody's fault but ours: a route asked for a feature that does not
            // exist. Loud, because the alternative is a route that quietly lets
            // everyone through.
            throw new LogicException("Onbekende feature [{$key}] op een route.");
        }

        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            throw new LogicException('feature-middleware op een route zonder workspace.');
        }

        /*
         * 404 and not 403. A permission is about who is asking, and answering
         * "not you" tells them the thing is there. A feature that is off is not
         * there at all in this workspace, and that is what a 404 says.
         */
        abort_unless($workspace->hasFeature($feature), 404);

        return $next($request);
    }
}
