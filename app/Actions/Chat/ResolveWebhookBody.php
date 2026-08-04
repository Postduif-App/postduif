<?php

namespace App\Actions\Chat;

use App\Models\Webhook;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Work out what an incoming webhook is actually saying.
 *
 * Two contracts, and which one applies is a property of the webhook rather than
 * of the request: without a path it is the original {"text": "..."}, and with
 * one the sender keeps sending whatever it already sends and we point at the
 * part we want.
 *
 * Every refusal here says what was wrong in terms the sender can act on. A
 * webhook is set up once by somebody reading an HTTP response, and "422" on its
 * own means they have to guess.
 */
class ResolveWebhookBody
{
    /**
     * Long enough for a stack trace somebody pasted in, short enough that a
     * runaway integration cannot fill a channel with one message.
     */
    public const MAX_LENGTH = 4000;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function handle(Webhook $webhook, array $payload): string
    {
        return $webhook->body_path === null
            ? $this->fromTextField($payload)
            : $this->fromPath($webhook->body_path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromTextField(array $payload): string
    {
        $text = $payload['text'] ?? null;

        if (! is_string($text)) {
            throw ValidationException::withMessages([
                'text' => __('requests.webhook.text_required'),
            ]);
        }

        return $this->clean($text, 'text');
    }

    /**
     * Follow the path into whatever arrived.
     *
     * data_get walks dots, which is the whole feature: "issue.title" and
     * "commits.0.message" both work, and how deep it goes is the sender's
     * business rather than something we have to anticipate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fromPath(string $path, array $payload): string
    {
        $value = data_get($payload, $path);

        if ($value === null) {
            throw ValidationException::withMessages([
                'body' => __('requests.webhook.path_empty', ['path' => $path]),
            ]);
        }

        /*
         * A number or a true/false is a perfectly good thing to say out loud —
         * a build number, a status. A list or an object is not a message, and
         * turning one into "Array" or a blob of JSON would be posting something
         * nobody meant to send. Better to say the path points at the wrong
         * place, because it does.
         */
        if (is_array($value)) {
            throw ValidationException::withMessages([
                'body' => __('requests.webhook.path_not_text', ['path' => $path]),
            ]);
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return $this->clean((string) $value, 'body');
    }

    private function clean(string $text, string $field): string
    {
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages([
                $field => __('requests.webhook.message_empty'),
            ]);
        }

        if (Str::length($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                $field => __('requests.webhook.message_too_long', ['count' => self::MAX_LENGTH]),
            ]);
        }

        return $text;
    }
}
