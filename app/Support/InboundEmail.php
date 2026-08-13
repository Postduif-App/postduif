<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One incoming e-mail, in the shape the rest of the application wants it.
 *
 * A value object between the provider and everything downstream, so that
 * "Postmark calls it TextBody and Lettermint calls it text" is a fact confined
 * to this file. Without it every provider added later would be a change to the
 * action that opens tickets, which is where the rules live and the last place
 * that should be learning new JSON.
 *
 * Nothing here decides anything. It reads a payload and says what was in it —
 * whether that becomes a new ticket or a reply on an old one is
 * ReceiveInboundEmail's business.
 */
class InboundEmail
{
    /**
     * @param  string  $from  The sender's address, lowercased.
     * @param  string|null  $fromName  As their mail client wrote it, if at all.
     * @param  array<int, string>  $to  Every address it was delivered to, which
     *                                  is where a +t<number> reply tag is found.
     * @param  array<int, string>  $references  Message ids this mail answers,
     *                                          newest first.
     */
    public function __construct(
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $messageId,
        public readonly array $references,
    ) {}

    /**
     * Read whatever the provider posted.
     *
     * One reader for all of them rather than one per provider, because the
     * providers agree about far more than they disagree about: every one of
     * them sends a from, a subject and a body, and they differ only in what
     * they call them. A switch on the provider would also mean the endpoint had
     * to be told which provider is posting to it, which is a second thing to
     * configure and a second thing to get wrong.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $from = self::pick($payload, ['FromFull.Email', 'from.email', 'from_email', 'from', 'sender']);

        return new self(
            from: Str::lower(self::address((string) $from)),
            fromName: self::pick($payload, ['FromFull.Name', 'from.name', 'from_name']) ?: self::name((string) $from),
            to: self::recipients($payload),
            subject: (string) (self::pick($payload, ['Subject', 'subject']) ?? ''),
            /*
             * The plain text, never the HTML. A ticket body is read in a chat
             * client and quoted back into a channel, and taking the HTML would
             * mean either rendering somebody else's markup or stripping it —
             * the first is a hole and the second produces worse text than the
             * plain part the sender's client already wrote.
             */
            body: self::stripQuotedReply((string) (self::pick($payload, ['TextBody', 'text', 'plain', 'body']) ?? '')),
            messageId: self::pick($payload, ['MessageID', 'message_id', 'MessageId', 'headers.message-id']),
            references: self::references($payload),
        );
    }

    /**
     * The reply tag in one of the recipient addresses, as a ticket number.
     *
     * Plus-addressing rather than a subject line with "[#42]" in it: a subject
     * is edited, translated and truncated by the people and clients it passes
     * through, while an address is copied back verbatim by every mail client
     * that ever existed. Null when nothing carries one, which is what a first
     * mail looks like.
     */
    public function replyTag(): ?int
    {
        foreach ($this->to as $address) {
            if (preg_match('/\+t(\d+)@/', $address, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * A subject worth putting on a ticket.
     *
     * The reply prefixes come off, because a queue where half the rows begin
     * "Re:" is a queue that reads as a mailbox. An empty subject gets a
     * sentence rather than an empty title: a ticket with no name is one nobody
     * can refer to.
     */
    public function ticketTitle(): string
    {
        $subject = trim(preg_replace('/^((re|fwd?|aw|antw)\s*(\[\d+\])?:\s*)+/i', '', $this->subject) ?? '');

        return $subject === '' ? __('mail.inbound.no_subject') : Str::limit($subject, 180);
    }

    /**
     * Everything this was delivered to, from whichever field the provider used.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private static function recipients(array $payload): array
    {
        $found = [];

        foreach (['ToFull', 'to', 'To', 'recipients', 'OriginalRecipient', 'original_recipient'] as $key) {
            $value = data_get($payload, $key);

            foreach (is_array($value) ? $value : [$value] as $entry) {
                $address = is_array($entry)
                    ? ($entry['Email'] ?? $entry['email'] ?? null)
                    : $entry;

                if (is_string($address) && $address !== '') {
                    $found[] = Str::lower(self::address($address));
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The message ids this mail is an answer to.
     *
     * In-Reply-To first, because it names the one message actually being
     * replied to; References after it, which is the whole thread and is what
     * survives when a client drops the first. Both are searched — a mail client
     * that sends neither is one whose reply opens a new ticket, which is wrong
     * but not harmful.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private static function references(array $payload): array
    {
        $named = self::namedHeaders($payload);

        $raw = implode(' ', array_filter([
            (string) (self::pick($payload, ['headers.in-reply-to', 'InReplyTo', 'in_reply_to']) ?? ''),
            (string) (self::pick($payload, ['headers.references', 'References', 'references']) ?? ''),
            $named['in-reply-to'] ?? '',
            $named['references'] ?? '',
        ]));

        preg_match_all('/<([^<>]+)>/', $raw, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The headers a provider sends as a list rather than as a map.
     *
     * Postmark's shape, and the reason this exists: it posts
     * Headers: [{Name: 'References', Value: '<...>'}] and no References key of
     * its own, so the whole message-id fallback above was reading nothing for
     * the provider it was written against. Only felt once a mail went back out
     * with an id worth quoting — before that, every reply carried the +t tag
     * and never reached the fallback.
     *
     * Lowercased, because a header name is case-insensitive and the two
     * providers that send them this way disagree about the capitals.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function namedHeaders(array $payload): array
    {
        $headers = data_get($payload, 'Headers', data_get($payload, 'headers'));

        if (! is_array($headers)) {
            return [];
        }

        $named = [];

        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = data_get($header, 'Name', data_get($header, 'name'));
            $value = data_get($header, 'Value', data_get($header, 'value'));

            if (is_string($name) && is_string($value)) {
                $named[Str::lower($name)] = $value;
            }
        }

        return $named;
    }

    /**
     * The first of these keys the payload actually has.
     *
     * Dotted, so a provider that nests its headers is read with the same list
     * as one that flattens them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private static function pick(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** The address out of "Naam <adres@example.com>", or the string as it came. */
    private static function address(string $value): string
    {
        return preg_match('/<([^<>]+)>/', $value, $matches) === 1
            ? trim($matches[1])
            : trim($value);
    }

    /** The display name out of "Naam <adres@example.com>", if there is one. */
    private static function name(string $value): ?string
    {
        if (preg_match('/^\s*"?([^"<]+?)"?\s*</', $value, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) ?: null;
    }

    /**
     * Cut the quoted original off the bottom of a reply.
     *
     * Best effort and deliberately conservative: it cuts at the first line that
     * is unmistakably a quote header — "Op ... schreef ...", "On ... wrote:",
     * a run of "> " lines, or an Outlook separator — and otherwise leaves the
     * text alone. Getting this wrong in the other direction would delete what
     * somebody actually wrote, so anything ambiguous is kept.
     */
    private static function stripQuotedReply(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (
                /*
                 * "On 3 March 2027 Support wrote:" and "Op 3 maart 2027
                 * schreef Support:" — the verb lands in a different place in
                 * each, so the pattern allows anything between it and the
                 * colon that ends the line rather than pinning it to the end.
                 */
                preg_match('/^(on|op)\s.{4,}(wrote|schreef|geschreven)\b.*:$/iu', $trimmed) === 1
                || preg_match('/^-{2,}\s*(original message|oorspronkelijk bericht)\s*-{2,}$/iu', $trimmed) === 1
                || preg_match('/^_{5,}$/', $trimmed) === 1
                || str_starts_with($trimmed, '>')
            ) {
                break;
            }

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }
}
