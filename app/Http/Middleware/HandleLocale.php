<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer in the language the reader asked for.
 *
 * Three sources, in order of how deliberate they are: what the member set, what
 * their browser says, and the application default. The browser sits in the
 * middle on purpose — it is a real preference, expressed once and then
 * forgotten about, and honouring it is what keeps somebody's first screen from
 * being in a language they cannot read.
 *
 * That first screen matters more here than in most applications: a download
 * link, a request for a password and the public site are all visited by people
 * with no account, who have nowhere to have set anything.
 */
class HandleLocale
{
    /** The languages this application actually has translations for. */
    public const SUPPORTED = ['nl', 'en'];

    /**
     * Where a visitor's own choice is kept.
     *
     * A cookie because the people who need it have no account to save it
     * against — see LocaleController. Left unencrypted in bootstrap/app.php,
     * like the appearance cookie: there is nothing in "nl" worth hiding.
     */
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $chosen = $request->user()?->locale;

        if ($chosen !== null && in_array($chosen, self::SUPPORTED, true)) {
            return $chosen;
        }

        /*
         * Below the member's own setting and above the browser's. It is a
         * deliberate choice, so it beats a header nobody remembers setting —
         * but the settings screen asks the same question outright, and an
         * answer given there should not be overruled by a link somebody
         * pressed on the public site.
         */
        $switched = $request->cookie(self::COOKIE);

        if (is_string($switched) && in_array($switched, self::SUPPORTED, true)) {
            return $switched;
        }

        // getPreferredLanguage picks the best match from Accept-Language and
        // falls back to the first option when the header is absent or asks for
        // something we do not have.
        return $request->getPreferredLanguage(self::SUPPORTED)
            ?? config('app.locale');
    }
}
