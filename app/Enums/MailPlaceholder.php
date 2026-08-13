<?php

namespace App\Enums;

use App\Http\Middleware\HandleLocale;

/**
 * The things a workspace may point at from inside its own mail text.
 *
 * A closed list, and it has to be: what a placeholder stands for is fetched off
 * a contract and a signer, so a name nobody wrote code for could only ever
 * resolve to nothing. Somebody who mistypes one gets it back verbatim in the
 * preview — see RenderMailTemplate — which is a better answer than a silent gap
 * where the deadline was supposed to be.
 *
 * The case values are the canonical English names and they are what the parser
 * really matches on. Every case also answers to the word for it in each
 * supported language, so a Dutch admin types {{ondertekenaar}} and the same
 * text still renders when the mail goes out in English. That is the whole point
 * of aliases(): the language the template was *written* in is a fact about the
 * author, and the language it is *read* in is a fact about the recipient, and
 * those two are not the same person.
 */
enum MailPlaceholder: string
{
    /**
     * Where the button goes, and the one placeholder that stands for something
     * rather than someone.
     *
     * Leave it out and the button is appended after the text instead. It is the
     * only part of these mails that cannot be dropped — a signing request
     * without a way to sign is a mail that wastes everybody's afternoon — so
     * the renderer never lets a template lose it.
     */
    case Button = 'button';

    case Signer = 'signer';
    case Sender = 'sender';
    case Workspace = 'workspace';
    case Title = 'title';

    /** The note the author typed for this one contract, if they typed one. */
    case Message = 'message';

    case Expires = 'expires';
    case SignedAt = 'signed_at';

    /**
     * What this is called in the language somebody is editing in.
     *
     * The chip in the settings screen and the placeholder as it goes into the
     * text: an English admin inserts {{signer}} and a Dutch one inserts
     * {{ondertekenaar}}, and both work everywhere.
     */
    public function label(): string
    {
        return __('mail_templates.placeholder.'.$this->value);
    }

    public function hint(): string
    {
        return __('mail_templates.hint.'.$this->value);
    }

    /**
     * Every spelling this placeholder answers to, lower-cased.
     *
     * All languages at once rather than only the current one. A template is
     * written once and read in whichever language the recipient gets — see the
     * class note — so a parser that only knew today's locale would quietly
     * blank out half a Dutch text the first time it went to an English reader.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        $names = [$this->value];

        foreach (HandleLocale::SUPPORTED as $locale) {
            $names[] = __('mail_templates.placeholder.'.$this->value, [], $locale);
        }

        return array_values(array_unique(
            array_map(fn (string $name): string => mb_strtolower($name), $names)
        ));
    }

    /**
     * The case a written-out name belongs to, or null when nobody wrote code
     * for it.
     */
    public static function matching(string $name): ?self
    {
        $wanted = mb_strtolower(trim($name));

        foreach (self::cases() as $case) {
            if (in_array($wanted, $case->aliases(), true)) {
                return $case;
            }
        }

        return null;
    }
}
