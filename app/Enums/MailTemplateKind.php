<?php

namespace App\Enums;

/**
 * Which of the mails to somebody outside the workspace this text belongs to.
 *
 * Only the two contract mails, and deliberately so. An invitation and a
 * transfer are the application talking about itself — "je bent uitgenodigd voor
 * Postduif" — while these two are a workspace talking to its own client about
 * its own document. That is the difference that decides whether it is worth
 * being able to rewrite something, and it is why the list is short rather than
 * every mailable in app/Mail.
 *
 * The reminder is not a case of its own: it is ContractRequestMail sent a
 * second time, and giving it its own text would be offering somebody two
 * screens to keep in step for no gain — see RemindContractSigners.
 */
enum MailTemplateKind: string
{
    case ContractRequest = 'contract_request';
    case ContractSigned = 'contract_signed';

    public function label(): string
    {
        return __('enums.mail-template-kind.label.'.$this->name);
    }

    public function description(): string
    {
        return __('enums.mail-template-kind.description.'.$this->name);
    }

    /**
     * Where this kind's untouched text lives.
     *
     * The default is a translation line like any other rather than something
     * hardcoded here, which is what makes "leeg gelaten" and "de standaardtekst"
     * the same thing in two languages without this enum knowing either of them.
     */
    public function translationKey(string $part): string
    {
        return match ($this) {
            self::ContractRequest => 'mail.contract.'.$part,
            self::ContractSigned => 'mail.contract_signed.'.$part,
        };
    }

    /**
     * What may be pointed at from inside this kind's text.
     *
     * Different per kind on purpose. A signed document has no deadline left to
     * mention and a request has no signing date yet, and offering either one
     * would be offering a placeholder that renders as nothing — which the line
     * rule then swallows, leaving somebody staring at a sentence that vanished.
     *
     * @return list<MailPlaceholder>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::ContractRequest => [
                MailPlaceholder::Button,
                MailPlaceholder::Signer,
                MailPlaceholder::Sender,
                MailPlaceholder::Workspace,
                MailPlaceholder::Title,
                MailPlaceholder::Message,
                MailPlaceholder::Expires,
            ],
            self::ContractSigned => [
                MailPlaceholder::Button,
                MailPlaceholder::Signer,
                MailPlaceholder::Sender,
                MailPlaceholder::Workspace,
                MailPlaceholder::Title,
                MailPlaceholder::SignedAt,
            ],
        };
    }

    /**
     * Whether this kind knows what to do with a given placeholder.
     *
     * Asked by validation, so that {{vervaldatum}} in the text of a mail that
     * carries a finished document is refused at the point somebody typed it,
     * rather than discovered by a client three weeks later.
     */
    public function allows(MailPlaceholder $placeholder): bool
    {
        return in_array($placeholder, $this->placeholders(), true);
    }
}
