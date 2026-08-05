<?php

namespace App\Actions\Chat;

use App\Models\LinkPreview;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Find out what a shared link is, once.
 *
 * Everything here is written against a hostile page at the other end. It may be
 * slow, enormous, not a web page at all, or an address inside our own network
 * wearing a public name — so there is a timeout, a byte ceiling, a content-type
 * check, and an address check at every hop rather than only at the start.
 *
 * Runs on a queue, never while a message is being sent: a message must not wait
 * on somebody else's server, and a page that never answers must not hold up the
 * conversation it was pasted into.
 */
class FetchLinkPreview
{
    public function __construct(private readonly PublicUrl $publicUrl) {}

    /** Long enough for a slow page, short enough not to hold a worker. */
    private const TIMEOUT_SECONDS = 5;

    /** A page's <head> is at the front; anything past this is not worth reading. */
    private const MAX_BYTES = 512 * 1024;

    /** Enough for a canonical URL to settle, few enough to notice a loop. */
    private const MAX_REDIRECTS = 3;

    /**
     * Fetch it unless we already know, and give back what is known either way.
     *
     * A row that failed is left alone rather than retried: "we tried and it is
     * not worth trying again" is exactly the state that keeps a hostile link
     * from turning every message into another outgoing request.
     */
    public function handle(string $url): LinkPreview
    {
        $existing = LinkPreview::query()
            ->where('url_hash', LinkPreview::hash($url))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $refusal = $this->publicUrl->refuse($url);

        if ($refusal !== null) {
            return $this->remember($url, ['failed_reason' => $refusal]);
        }

        try {
            return $this->remember($url, $this->read($url));
        } catch (Throwable $exception) {
            return $this->remember($url, [
                'failed_reason' => Str::limit($exception->getMessage(), 190),
            ]);
        }
    }

    /**
     * Read the page, as little of it as possible.
     *
     * @return array<string, mixed>
     */
    private function read(string $url): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withOptions([
                'stream' => true,
                'allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    // Referer off: the page being previewed has no business
                    // learning which of our URLs pointed at it.
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    /*
                     * The whole reason redirects are allowed at all rather than
                     * refused: a public URL that redirects to 127.0.0.1 is the
                     * standard way around a check done only at the start.
                     */
                    'on_redirect' => function (RequestInterface $request, $response, $to): void {
                        $refusal = $this->publicUrl->refuse((string) $to);

                        if ($refusal !== null) {
                            throw new \RuntimeException('Doorverwijzing geweigerd: '.$refusal);
                        }
                    },
                ],
            ])
            ->withHeaders([
                'Accept' => 'text/html',
                // Named rather than pretending to be a browser: whoever is
                // being fetched deserves to know who is asking.
                'User-Agent' => config('app.name').' link preview',
            ])
            ->get($url);

        if (! $response->successful()) {
            return ['failed_reason' => 'De pagina antwoordde met '.$response->status().'.'];
        }

        $type = mb_strtolower($response->header('Content-Type'));

        if (! str_contains($type, 'text/html')) {
            return ['failed_reason' => 'Dit is geen webpagina.'];
        }

        return $this->parse($this->firstBytes($response->toPsrResponse()->getBody()), $url);
    }

    /**
     * The front of the response and no more.
     *
     * Read in chunks rather than with getContents(), which would happily pull
     * a stream that never ends into memory.
     */
    private function firstBytes(StreamInterface $body): string
    {
        $html = '';

        while (! $body->eof() && strlen($html) < self::MAX_BYTES) {
            $html .= $body->read(8192);
        }

        return $html;
    }

    /**
     * Pull the handful of tags a preview is made of.
     *
     * Open Graph first, then the ordinary tags: og:title is what a page says
     * about itself when it is being shared, which is exactly this case.
     *
     * @return array<string, mixed>
     */
    private function parse(string $html, string $pageUrl): array
    {
        $title = $this->meta($html, 'og:title')
            ?? $this->titleTag($html);

        if ($title === null) {
            return ['failed_reason' => 'De pagina zegt niet hoe zij heet.'];
        }

        return [
            'title' => Str::limit($title, 190),
            'description' => Str::limit(
                $this->meta($html, 'og:description') ?? $this->meta($html, 'description') ?? '',
                400,
            ) ?: null,
            'image_url' => $this->image($html, $pageUrl),
            'site_name' => Str::limit($this->meta($html, 'og:site_name') ?? '', 190) ?: null,
            'fetched_at' => now(),
        ];
    }

    /**
     * The picture the page offers, as an address a browser can actually load.
     *
     * Two things have to happen to it. An og:image is very often relative —
     * "/og/home.png" — and stored as it stands it would point at *our* origin,
     * which is a broken image at best and a request to ourselves at worst. So
     * it is resolved against the page it came from.
     *
     * And then it is held to the same rule as the page was. The address check
     * on the page stops our server from being aimed at the inside of our own
     * network; without this, a page could still name an og:image on a private
     * address and have every reader's browser fetch it — a smaller thing,
     * because it is their network and not ours, but the same shape of trick and
     * we already have the answer to it lying around.
     */
    private function image(string $html, string $pageUrl): ?string
    {
        $image = $this->meta($html, 'og:image');

        if ($image === null) {
            return null;
        }

        $absolute = $this->absolute($image, $pageUrl);

        if ($absolute === null || $this->publicUrl->refuse($absolute) !== null) {
            return null;
        }

        return Str::limit($absolute, 2000, '');
    }

    /**
     * A possibly relative address, against the page that named it.
     *
     * Hand-resolved rather than through a library, because only three shapes
     * turn up in practice: an absolute URL, one starting at the root, and one
     * relative to the page's own directory. Anything else — a data: URI, a
     * protocol nobody previews — comes back null and the card simply has no
     * picture, which is a better answer than a guess.
     */
    private function absolute(string $image, string $pageUrl): ?string
    {
        if (preg_match('/^https?:\/\//i', $image) === 1) {
            return $image;
        }

        // Protocol-relative: //cdn.example/og.png takes the page's own scheme.
        if (str_starts_with($image, '//')) {
            return (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https').':'.$image;
        }

        // Anything that is not a path at all — data:, mailto:, javascript: —
        // is not a picture we are going to point a browser at.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $image) === 1) {
            return null;
        }

        $parts = parse_url($pageUrl);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($image, '/')) {
            return $origin.$image;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$directory.'/'.$image;
    }

    private function meta(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');

        // Both orders, because the attributes come in either — and both
        // property= (Open Graph) and name= (everything else).
        foreach ([
            '/<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']*)["\']/i',
            '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$quoted.'["\']/i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return $this->clean($matches[1]);
            }
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1
            ? $this->clean($matches[1])
            : null;
    }

    private function clean(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function remember(string $url, array $attributes): LinkPreview
    {
        /*
         * firstOrCreate rather than create, because url_hash is unique and two
         * jobs can be in the air for the same link — the check at the top of
         * handle() and the insert down here are not one moment. Whoever loses
         * that race gets the row the other one wrote, which says the same
         * thing: this link has been looked at.
         */
        return LinkPreview::firstOrCreate(
            ['url_hash' => LinkPreview::hash($url)],
            ['url' => Str::limit($url, 2000, ''), ...$attributes],
        );
    }
}
