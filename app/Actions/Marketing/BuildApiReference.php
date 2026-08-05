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
     * What each endpoint is for, and what it wants.
     *
     * Keyed by route name. The router supplies the method, the path and the
     * limit; none of that is repeated here, so none of it can disagree.
     *
     * @var array<string, array{summary: string, auth: string, params?: array<int, array{name: string, rule: string, about: string}>, returns?: string}>
     */
    private const NOTES = [
        'api.v1.status.show' => [
            'summary' => 'De status van de member wiens token je stuurt.',
            'auth' => 'token',
            'returns' => 'emoji, text, availability, label, isManual',
        ],
        'api.v1.status.update' => [
            'summary' => 'Zet je eigen status. Loopt langs dezelfde actie als het scherm, dus een statusregel die later aan de beurt is neemt het weer over.',
            'auth' => 'token',
            'params' => [
                ['name' => 'availability', 'rule' => 'verplicht', 'about' => 'available, away of do-not-disturb'],
                ['name' => 'emoji', 'rule' => 'optioneel, max 16', 'about' => 'Eén emoji; meerdere code points tellen als één teken niet mee'],
                ['name' => 'text', 'rule' => 'optioneel, max 100', 'about' => 'Wat je aan het doen bent'],
            ],
            'returns' => 'emoji, text, availability, label, isManual',
        ],
        'api.v1.channels.index' => [
            'summary' => 'De kanalen die dit token kan zien, om er een id uit te halen. Het chatscherm laat geen ids zien, dus zonder deze lijst is de volgende aanroep niet te doen.',
            'auth' => 'token',
            'params' => [
                ['name' => 'search', 'rule' => 'optioneel, query', 'about' => 'Filtert op naam, hoofdletterongevoelig'],
            ],
            'returns' => 'id, name, label, type, topic, workspace, canPost — hoogstens 50',
        ],
        'api.v1.messages.store' => [
            'summary' => 'Zeg iets in een kanaal. Hetzelfde bericht als vanaf het scherm: het draagt je naam, je kunt het bewerken en verwijderen, en niets markeert het als afkomstig van een script.',
            'auth' => 'token',
            'params' => [
                ['name' => 'channel_id', 'rule' => 'verplicht', 'about' => 'Uit GET /v1/channels'],
                ['name' => 'body', 'rule' => 'verplicht, max 4000', 'about' => 'De tekst zelf; bijlagen kunnen hier niet'],
                ['name' => 'parent_id', 'rule' => 'optioneel, ULID', 'about' => 'Antwoord in een bestaande thread in hetzelfde kanaal'],
            ],
            'returns' => 'id, channelId, body, parentId, sentAt',
        ],
        'webhooks.messages.store' => [
            'summary' => 'Een bericht posten zonder token van een persoon. De sleutel zit in het pad, want dat is wat de tools die hierop wijzen verwachten — en dat is ook waarom een webhook in te trekken en opnieuw te maken is.',
            'auth' => 'webhook',
        ],
        'workflows.webhook' => [
            'summary' => 'Zet een workflow aan. Strakker begrensd dan een bericht-webhook: hierachter zit geen enkel bericht maar een rij stappen die in meerdere kanalen kan posten en mensen kan toevoegen.',
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

        foreach ($router->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! array_key_exists($name, self::NOTES)) {
                continue;
            }

            $endpoints[] = [
                'name' => $name,
                // HEAD rides along with every GET and says nothing anybody
                // needs to read.
                'method' => implode(', ', array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.ltrim($route->uri(), '/'),
                'limiter' => $this->limiterFor($name),
                ...self::NOTES[$name],
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
