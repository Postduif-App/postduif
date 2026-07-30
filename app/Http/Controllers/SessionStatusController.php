<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionStatusController extends Controller
{
    /**
     * Answer whether the caller is still signed in.
     *
     * Deliberately outside the "auth" middleware: that would redirect a guest
     * to the login page, and a redirect is indistinguishable from a healthy
     * response to the browser check that calls this. A bare 204 or 401 is not.
     *
     * The chat screen sits open for hours without making a single request, so
     * without something like this a member whose session ended keeps watching
     * messages arrive on a page they are no longer allowed to see.
     */
    public function __invoke(Request $request): Response
    {
        return response()->noContent(
            $request->user() === null
                ? Response::HTTP_UNAUTHORIZED
                : Response::HTTP_NO_CONTENT
        );
    }
}
