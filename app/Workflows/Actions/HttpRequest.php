<?php

namespace App\Workflows\Actions;

use App\Workflows\GuardOutboundUrl;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Ask something outside this application, and remember what it said.
 *
 * The one action that reaches off the machine, which makes it the one that
 * needs saying no: see GuardOutboundUrl, which decides whether an address is
 * the internet or somebody's inside. Everything else here is a bound —
 * redirects, how long to wait, how much of an answer to keep.
 *
 * What comes back is filed under the step, so a later step can write
 * {{ steps.2.json.order.id }} the way it reads anything else the run has picked
 * up. The decoded body sits under `json` and the raw text under `body`, because
 * plenty of things answer with neither JSON nor an error.
 */
class HttpRequest extends WorkflowAction
{
    public function __construct(
        private readonly GuardOutboundUrl $guard,
    ) {}

    public static function label(): string
    {
        return __('workflows.actions.http-request.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.http-request.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::choice('method', __('workflows.actions.http-request.method.label'), [
                'get' => 'GET',
                'post' => 'POST',
                'put' => 'PUT',
                'patch' => 'PATCH',
                'delete' => 'DELETE',
            ]),
            WorkflowField::text(
                'url',
                __('workflows.actions.http-request.url.label'),
                __('workflows.actions.http-request.url.hint'),
            ),
            WorkflowField::longText(
                'headers',
                __('workflows.actions.http-request.headers.label'),
                __('workflows.actions.http-request.headers.hint'),
                required: false,
            ),
            WorkflowField::longText(
                'body',
                __('workflows.actions.http-request.body.label'),
                __('workflows.actions.http-request.body.hint'),
                required: false,
            ),
        ];
    }

    /**
     * What a later step may read.
     *
     * `json` is offered as one path rather than as the paths inside it, because
     * the shape belongs to whoever answers and we have not spoken to them yet.
     * What makes that workable is the run screen: it shows the answer that came
     * back, so the way to find {{ steps.2.json.order.id }} is to let it run once
     * and read what it got.
     *
     * @return array<string, string>
     */
    public static function provides(): array
    {
        return [
            'status' => __('workflows.provides.http.status'),
            'ok' => __('workflows.provides.http.ok'),
            'json' => __('workflows.provides.http.json'),
            'body' => __('workflows.provides.http.body'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $url = $this->guard->handle((string) $context->setting('url', ''));

        $method = strtolower((string) $context->setting('method', 'get'));

        if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
            throw new RuntimeException(__('workflows.errors.http_method'));
        }

        $body = trim((string) $context->setting('body', ''));

        $request = Http::withHeaders($this->headers($context))
            ->timeout((int) config('workflows.http.timeout'))
            /*
             * A redirect is a second address, chosen by the thing we just
             * asked. Everything GuardOutboundUrl decided about the first one
             * would have to be decided again about that one, and following it
             * without asking is the ordinary way an outbound-request feature
             * ends up reaching localhost after all.
             */
            ->withoutRedirecting()
            // Read in hand-sized pieces rather than swallowed whole, so that an
            // answer nobody promised to keep short cannot take the worker's
            // memory with it. See body().
            ->withOptions(['stream' => true]);

        try {
            /*
             * Sent as a string rather than as an array, because what somebody
             * typed is what should go over the wire. A body run through
             * json_encode again would quietly repair invalid JSON here and fail
             * at the far end, where nobody is reading.
             */
            $response = $body === ''
                ? $request->send($method, $url)
                : $request->withBody($body, $this->contentType($context))->send($method, $url);
        } catch (ConnectionException $exception) {
            /*
             * Said in our own words. A client's message is written for a
             * developer reading a stack trace, and this one ends up on the run
             * screen in front of somebody who wants to know whether to fix the
             * address or wait.
             */
            throw new RuntimeException(__('workflows.errors.http_unreachable'));
        }

        $text = $this->body($response->toPsrResponse()->getBody());

        return [
            'status' => $response->status(),

            /*
             * Whether the far end was happy, as a thing a condition can read.
             * Without it every workflow that wants "and only carry on if it
             * worked" has to compare the status to 200 and be wrong about 201.
             */
            'ok' => $response->successful(),

            'json' => $this->decoded($text),
            'body' => $text,
        ];
    }

    /**
     * The headers as the step's own lines describe them.
     *
     * One "Naam: waarde" per line, which is how everybody has written a header
     * since headers existed — and, unlike a pair of fields, it holds the two or
     * three an API key usually needs.
     *
     * @return array<string, string>
     */
    private function headers(WorkflowStepContext $context): array
    {
        $headers = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $context->setting('headers', '')) ?: [] as $line) {
            if (trim($line) === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            $name = trim($name);

            /*
             * Host is refused. It is the one header that says something about
             * where the request goes rather than what it carries, and letting a
             * step set it would mean the address GuardOutboundUrl approved and
             * the name the far end answers to are two different things.
             */
            if ($name === '' || strcasecmp($name, 'host') === 0) {
                continue;
            }

            $headers[$name] = trim($value);
        }

        return $headers;
    }

    /**
     * What the body is, unless the step has already said.
     *
     * JSON is the guess because it is what nearly everything wants now, and
     * because a body typed into a box on this screen is nearly always a small
     * object. A step that means something else says so in its headers.
     */
    private function contentType(WorkflowStepContext $context): string
    {
        foreach ($this->headers($context) as $name => $value) {
            if (strcasecmp($name, 'content-type') === 0) {
                return $value;
            }
        }

        return 'application/json';
    }

    /**
     * As much of the answer as we are willing to keep.
     *
     * Read rather than taken whole: this ends up in the run's context, which is
     * a column in our database and a screen a beheerder reads, so both the
     * memory and the amount of somebody else's data we store want a ceiling.
     * What is cut off is said out loud — a body that stops halfway through a
     * sentence should not read as the whole answer.
     */
    private function body(StreamInterface $stream): string
    {
        $limit = (int) config('workflows.http.max_response_bytes');

        $text = '';

        while (! $stream->eof() && strlen($text) <= $limit) {
            $chunk = $stream->read($limit + 1 - strlen($text));

            if ($chunk === '') {
                break;
            }

            $text .= $chunk;
        }

        return strlen($text) > $limit
            ? substr($text, 0, $limit).__('workflows.value.truncated')
            : $text;
    }

    /**
     * The body as something a later step can reach into, or nothing.
     *
     * Only an array: a bare "true" or a number is valid JSON and utterly
     * useless as a thing to write {{ steps.2.json.something }} against, and
     * offering it would mean a variable that resolves to nothing for a reason
     * nobody can see.
     *
     * @return array<mixed>|null
     */
    private function decoded(string $text): ?array
    {
        $decoded = json_decode($text, associative: true);

        return is_array($decoded) ? $decoded : null;
    }
}
