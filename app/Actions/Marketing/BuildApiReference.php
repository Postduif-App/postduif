<?php

namespace App\Actions\Marketing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The reference for everything this application answers to over HTTP.
 *
 * Built the way the rest of the public site is built — see MarketingController:
 * the inventory is read off the router rather than typed out here, because
 * documentation written by hand becomes a promise the code has stopped keeping
 * and nobody notices until somebody's script breaks.
 *
 * The prose is the part a router cannot supply, so it is written below and
 * keyed by route name. That pairing is deliberate and it is tested: a new route
 * with no entry, or an entry naming a route that no longer exists, fails
 * ApiReferenceTest rather than quietly shipping a page that is missing an
 * endpoint or inventing one.
 */
class BuildApiReference
{
    /**
     * The shape of each endpoint, and nothing anybody reads.
     *
     * Keyed by route name. The router supplies the method, the path and the
     * limit; the sentences live in lang/*\/marketing.php under the same route
     * name with its dots turned into underscores. So this holds what is true of
     * the API — which keys exist, what they take, what comes back — and the
     * prose that explains it is translated beside the rest of the public site.
     *
     * That pairing is tested in both directions: a new route with no entry, or
     * an entry naming a route that no longer exists, fails ApiReferenceTest
     * rather than quietly shipping a page missing an endpoint or inventing one.
     *
     * @var array<string, array{auth: string, params?: list<string>, returns?: string}>
     */
    private const NOTES = [
        'api.v1.status.show' => [
            'auth' => 'token',
            'returns' => 'emoji, text, availability, label, isManual',
        ],
        'api.v1.status.update' => [
            'auth' => 'token',
            'params' => ['availability', 'emoji', 'text'],
            'returns' => 'emoji, text, availability, label, isManual',
        ],
        'api.v1.channels.index' => [
            'auth' => 'token',
            'params' => ['search'],
            'returns' => 'id, name, label, type, topic, workspace, canPost',
        ],
        'api.v1.messages.store' => [
            'auth' => 'token',
            'params' => ['channel_id', 'body', 'parent_id'],
            'returns' => 'id, channelId, body, parentId, sentAt',
        ],
        'webhooks.messages.store' => [
            'auth' => 'webhook',
        ],
        'workflows.webhook' => [
            'auth' => 'webhook',
        ],
    ];

    /**
     * Which limiter guards which route, so the numbers below come from
     * AppServiceProvider rather than from somebody's memory of it.
     *
     * @var array<string, string>
     */
    private const LIMITERS = [
        'api.v1.' => 'api-token',
        'webhooks.messages.store' => 'webhook',
        'workflows.webhook' => 'workflow-webhook',
    ];

    /**
     * @return array{endpoints: array<int, array<string, mixed>>, limits: array<string, array{perMinute: int}>}
     */
    public function handle(Router $router): array
    {
        $endpoints = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! array_key_exists($name, self::NOTES)) {
                continue;
            }

            $note = self::NOTES[$name];
            $line = self::line($name);

            $endpoints[] = [
                'name' => $name,
                // HEAD rides along with every GET and says nothing anybody
                // needs to read.
                'method' => implode(', ', array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.ltrim($route->uri(), '/'),
                'limiter' => $this->limiterFor($name),
                'auth' => $note['auth'],
                'summary' => __("{$line}.summary"),
                /*
                 * The name is the API's and stays as it is; what a parameter
                 * asks for and what it means are sentences, so they are
                 * translated. Keeping the names here rather than in the lang
                 * file means a translator cannot rename a query parameter.
                 */
                'params' => array_map(fn (string $param): array => [
                    'name' => $param,
                    'rule' => __("{$line}.params.{$param}.rule"),
                    'about' => __("{$line}.params.{$param}.about"),
                ], $note['params'] ?? []),
                'returns' => $note['returns'] ?? null,
            ];
        }

        // The order the router happens to hold is the order they were
        // registered, which is near enough to the order somebody meets them.
        return [
            'endpoints' => $endpoints,
            'limits' => $this->limits(),
        ];
    }

    /**
     * Every route this reference claims to cover, for the test that pairs them.
     *
     * @return array<int, string>
     */
    public static function documented(): array
    {
        return array_keys(self::NOTES);
    }

    /**
     * Where a route's sentences live.
     *
     * A route name is dotted and so is a translation key, so the dots are
     * swapped for underscores rather than letting one nest inside the other.
     */
    private static function line(string $name): string
    {
        return 'marketing.api.'.str_replace('.', '_', $name);
    }

    private function limiterFor(string $name): string
    {
        foreach (self::LIMITERS as $prefix => $limiter) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                return $limiter;
            }
        }

        return '';
    }

    /**
     * The ceilings themselves, asked of the limiters rather than repeated.
     *
     * A limiter is a closure that wants a request, so it gets an empty one: all
     * three key on a credential that is not there, which changes the bucket
     * they would count into but not the number they allow.
     *
     * @return array<string, array{perMinute: int}>
     */
    private function limits(): array
    {
        $limits = [];

        foreach (array_unique(array_values(self::LIMITERS)) as $name) {
            $limiter = RateLimiter::limiter($name);

            if ($limiter === null) {
                continue;
            }

            $limit = $limiter(Request::create('/'));

            if ($limit instanceof Limit) {
                $limits[$name] = ['perMinute' => $limit->maxAttempts];
            }
        }

        return $limits;
    }
}
