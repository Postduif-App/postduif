<?php

namespace App\Enums;

/**
 * How a workspace's mail leaves the building.
 *
 * A closed list, like every other catalogue here, and for the same reason: each
 * of these has to resolve to a Symfony transport that actually exists. A
 * workspace that could type its own driver name would be choosing an error
 * message.
 *
 * The values are the transport names Laravel's MailManager knows, which is what
 * lets the settings row be handed to Mail::build almost as it stands — see
 * WorkspaceMailSettings::mailerConfig. Default is the exception and names no
 * transport at all: it means "do not build one", not "build a transport called
 * default".
 */
enum MailTransport: string
{
    /**
     * Whatever the application itself is configured with.
     *
     * The starting point for every workspace, and the answer to the question
     * this whole screen risks getting wrong: somebody who opens it, looks
     * around and saves without meaning to change anything must not thereby
     * break their invitations.
     */
    case Default = 'default';

    case Smtp = 'smtp';
    case Postmark = 'postmark';
    case Lettermint = 'lettermint';

    public function label(): string
    {
        return match ($this) {
            self::Default => __('enums.mail-transport.label.Default'),
            self::Smtp => __('enums.mail-transport.label.Smtp'),
            self::Postmark => __('enums.mail-transport.label.Postmark'),
            self::Lettermint => __('enums.mail-transport.label.Lettermint'),
        };
    }

    /**
     * What choosing it means, in a sentence.
     *
     * Three of these four are a decision about a supplier somebody has to have
     * an account with, so the screen says which one rather than leaving a brand
     * name to carry it.
     */
    public function description(): string
    {
        return match ($this) {
            self::Default => __('enums.mail-transport.description.Default'),
            self::Smtp => __('enums.mail-transport.description.Smtp'),
            self::Postmark => __('enums.mail-transport.description.Postmark'),
            self::Lettermint => __('enums.mail-transport.description.Lettermint'),
        };
    }

    /**
     * Whether this one is configured per workspace at all.
     *
     * Asked before validating, before building a mailer and before deciding
     * whether the screen has anything to show below the picker — three places
     * that would otherwise each compare against Default and be free to disagree.
     */
    public function isConfigurable(): bool
    {
        return $this !== self::Default;
    }

    /**
     * The columns this transport reads, so that choosing another one can leave
     * the rest behind.
     *
     * Named here rather than in the controller because it is a fact about the
     * transport: switching from Postmark to SMTP has to clear the Postmark
     * token, or a workspace that thought it had removed its key would still
     * have it sitting in the database.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Default => [],
            self::Smtp => ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'],
            self::Postmark => ['postmark_token', 'postmark_message_stream'],
            self::Lettermint => ['lettermint_token', 'lettermint_route_id'],
        };
    }

    /**
     * Every column any transport reads.
     *
     * @return list<string>
     */
    public static function allFields(): array
    {
        return array_merge(...array_map(fn (self $case): array => $case->fields(), self::cases()));
    }
}
