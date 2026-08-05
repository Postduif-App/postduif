<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * What a crawler is told to read and what to leave alone.
 *
 * Both of these are routes rather than files in public/, and for the same
 * reason: this application is installed under whatever hostname somebody puts
 * it on. A sitemap written out once names the host it was written on, and
 * robots.txt has to point at that sitemap by absolute URL — the format allows
 * nothing else. Two static files would be right for exactly one installation.
 *
 * Note that a file in public/ would win over these, because the web server
 * answers it before the request ever reaches PHP. There is deliberately no
 * public/robots.txt for that reason.
 */
class IndexingController extends Controller
{
    /**
     * The public pages, which is all of them.
     *
     * Everything else this application answers to is either behind a login or
     * carries a token in the address — an invitation, a transfer, a webhook.
     * Those are not pages, and listing them would be the one place they could
     * be found.
     */
    private const PUBLIC_PAGES = ['home', 'docs'];

    /**
     * The paths worth saying out loud.
     *
     * Not a security measure and not treated as one — robots.txt is a request,
     * and the things that actually matter are behind auth. It is here so a
     * crawler spends its budget on the two pages that are meant to be found
     * rather than on a login screen it will be redirected away from.
     */
    private const CLOSED = [
        '/app',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/invitations/',
        '/join/',
        '/transfers/',
        '/api/',
    ];

    public function robots(): Response
    {
        $closed = collect(self::CLOSED)
            ->map(fn (string $path): string => 'Disallow: '.$path)
            ->implode("\n");

        $body = <<<TXT
        User-agent: *
        Allow: /
        {$closed}

        Sitemap: {$this->sitemapUrl()}
        TXT;

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = collect(self::PUBLIC_PAGES)
            ->map(fn (string $name): string => '    <url><loc>'.e(route($name)).'</loc></url>')
            ->implode("\n");

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        {$urls}
        </urlset>
        XML;

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** Absolute, because the format allows nothing else here. */
    private function sitemapUrl(): string
    {
        return route('sitemap');
    }
}
