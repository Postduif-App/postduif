<?php

namespace App\Enums;

/**
 * How an SMTP connection is protected.
 *
 * Three cases rather than a free-text field, because the two that people mean
 * when they say "TLS" are not the same thing and picking the wrong one is the
 * single most common reason an otherwise correct SMTP setup refuses to connect.
 * StartTls opens in the clear and upgrades; Tls is encrypted from the first
 * byte, which is what port 465 expects.
 *
 * None exists because internal relays exist. It is offered last and says what
 * it costs — a password over an unencrypted connection is a password on the
 * wire — rather than being left out and forcing somebody to give up on the
 * screen entirely.
 */
enum SmtpEncryption: string
{
    case StartTls = 'tls';
    case Tls = 'ssl';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::StartTls => __('enums.smtp-encryption.label.StartTls'),
            self::Tls => __('enums.smtp-encryption.label.Tls'),
            self::None => __('enums.smtp-encryption.label.None'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StartTls => __('enums.smtp-encryption.description.StartTls'),
            self::Tls => __('enums.smtp-encryption.description.Tls'),
            self::None => __('enums.smtp-encryption.description.None'),
        };
    }

    /**
     * The port this normally arrives on, offered as the default when somebody
     * picks it. A suggestion and not a rule — the field stays editable, because
     * plenty of relays listen somewhere else.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::StartTls => 587,
            self::Tls => 465,
            self::None => 25,
        };
    }

    /**
     * The Symfony DSN scheme, and whether STARTTLS may be attempted.
     *
     * The whole reason this enum exists rather than a string column that gets
     * handed to Laravel: "smtps" is a scheme, "STARTTLS" is a negotiation, and
     * "no encryption" is only reachable by switching off the automatic attempt.
     * One place turns the choice into those three, so nowhere else has to know.
     *
     * @return array{scheme: string, auto_tls: bool}
     */
    public function transportOptions(): array
    {
        return match ($this) {
            self::StartTls => ['scheme' => 'smtp', 'auto_tls' => true],
            self::Tls => ['scheme' => 'smtps', 'auto_tls' => true],
            self::None => ['scheme' => 'smtp', 'auto_tls' => false],
        };
    }
}
