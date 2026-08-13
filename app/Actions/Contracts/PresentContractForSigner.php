<?php

namespace App\Actions\Contracts;

use App\Enums\ContractFieldType;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;

/**
 * What one signer is shown, and nothing else.
 *
 * The filtering is the point rather than a nicety. A contract signed by a
 * landlord and a tenant carries both their boxes, and the tenant's page must
 * show only the tenant's — not because the landlord's are secret, but because a
 * page offering somebody twelve boxes of which six are not theirs is a page
 * nobody can fill in correctly.
 *
 * Everything here is public-facing: it goes to a browser with no account behind
 * it. So it carries no ids that are not needed, no other signer's answers, and
 * above all no tokens — see the payload below.
 */
class PresentContractForSigner
{
    /**
     * @return array{
     *     title: string,
     *     message: string|null,
     *     pageCount: int,
     *     expiresAt: string|null,
     *     signerName: string,
     *     signerCount: int,
     *     signedCount: int,
     *     marks: array<string, string|null>,
     *     fields: list<array<string, mixed>>,
     *     filled: list<array<string, mixed>>,
     * }
     */
    public function handle(ContractSigner $signer): array
    {
        $contract = $signer->contract;

        $contract->loadMissing(['fields', 'signers']);

        /** @var array<int, ContractFieldValue> $answered */
        $answered = $signer->values()->get()->keyBy('contract_field_id')->all();

        $mine = $contract->fields->filter(
            fn (ContractField $field): bool => $field->belongsToSigner($signer)
        );

        return [
            'title' => $contract->title,
            'message' => $contract->message,
            'pageCount' => $contract->page_count,
            'expiresAt' => $contract->expires_at?->toIso8601String(),

            /*
             * The person's own name, so the page can address them — this is
             * often the only confirmation somebody gets that the link they
             * followed was really meant for them rather than forwarded.
             */
            'signerName' => $signer->name,

            /*
             * How many people are involved and how far along it is. Counts
             * rather than names: who else was asked to sign is the author's
             * business, and a contract sent to a landlord and three prospective
             * tenants would otherwise hand each of them the others' identities.
             */
            'signerCount' => $contract->signers->count(),
            'signedCount' => $contract->signedCount(),

            /*
             * Where this person's own marks can be fetched, or null where they
             * have not made one yet.
             *
             * Keyed by the field type rather than sent per box, because there
             * is one mark of each kind and every box of that kind shows it —
             * see StoreSignature. Sending it per box would be the same URL
             * repeated nine times on a contract wanting initials on nine pages,
             * and nine requests for one image.
             *
             * A cache-buster on the URL because the address does not change
             * when the drawing behind it does: without it, somebody who cleared
             * a wobbly signature and drew a better one would keep being shown
             * the wobbly one by their own browser.
             */
            'marks' => [
                ContractFieldType::Signature->value => $signer->signature() === null
                    ? null
                    : $this->markUrl($signer, ContractFieldType::Signature),
                ContractFieldType::Initials->value => $signer->initials() === null
                    ? null
                    : $this->markUrl($signer, ContractFieldType::Initials),
            ],

            'fields' => $mine->values()->map(function (ContractField $field) use ($answered): array {
                $value = $answered[$field->id] ?? null;

                return [
                    'id' => $field->id,
                    'page' => $field->page,

                    // Cast to float: a decimal column comes back from PDO as a
                    // string, and the browser multiplies these by a page size.
                    'x' => (float) $field->x,
                    'y' => (float) $field->y,
                    'width' => (float) $field->width,
                    'height' => (float) $field->height,

                    'type' => $field->type->value,
                    'label' => $field->label,
                    'isRequired' => $field->is_required,

                    /*
                     * What was typed last time, so a half-filled contract
                     * survives the tab being closed. Null for a drawn field,
                     * whose image hangs on the signer — filled says whether it
                     * has been dealt with, which is the only thing a tickbox
                     * left unticked can be told apart by.
                     */
                    'value' => $value?->value,
                    'filled' => $value?->filled_at !== null,
                ];
            })->all(),

            /*
             * What the people before this person already put down.
             *
             * Read-only, and never mixed in with the list above: these are
             * boxes belonging to somebody else, and a page that let signer two
             * type into signer one's box would be handing out an edit to a
             * signature that has already been given.
             */
            'filled' => $this->alreadySigned($signer),
        ];
    }

    /**
     * The boxes other people have already dealt with, as this page should draw
     * them.
     *
     * Why this is here at all: a contract with three signers used to look
     * identical to the second and third of them as it did to the first — an
     * empty document with their own boxes on it. There was no way to tell "ik
     * ben de eerste" from "de anderen zijn al langs geweest", which is exactly
     * the thing somebody wants to know before they put their name under it.
     *
     * Only from signers who have *signed*. A half-typed draft is somebody
     * thinking out loud: it can still be cleared, retyped, or end in a refusal,
     * and showing it to the next person would present a guess as a commitment.
     * filled_at is the same line drawn per box — see ContractFieldValue.
     *
     * Still no names and still no tokens. What is shown is the contract as it
     * now reads, which is what everybody signing it is entitled to see; who was
     * asked remains the author's business — see the payload above.
     *
     * @return list<array<string, mixed>>
     */
    private function alreadySigned(ContractSigner $signer): array
    {
        $contract = $signer->contract;

        $others = $contract->signers
            ->filter(fn (ContractSigner $other): bool => $other->id !== $signer->id && $other->hasSigned())
            ->keyBy('id');

        if ($others->isEmpty()) {
            return [];
        }

        /*
         * Every answer in one query rather than one query per signer: a
         * contract signed by four people is a page that would otherwise open
         * with five round trips before it draws anything.
         */
        $answers = ContractFieldValue::query()
            ->whereIn('contract_signer_id', $others->keys()->all())
            ->whereNotNull('filled_at')
            ->get()
            ->groupBy('contract_signer_id');

        $filled = [];

        foreach ($others as $other) {
            /** @var array<int, ContractFieldValue> $theirs */
            $theirs = ($answers[$other->id] ?? collect())->keyBy('contract_field_id')->all();

            foreach ($contract->fields as $field) {
                if (! $field->belongsToSigner($other)) {
                    continue;
                }

                $box = $this->filledBox($signer, $field, $other, $theirs[$field->id] ?? null);

                if ($box !== null) {
                    $filled[] = $box;
                }
            }
        }

        return $filled;
    }

    /**
     * One of somebody else's boxes, or nothing where they left it empty.
     *
     * The split between drawn and typed runs through this whole feature: a
     * drawn field's answer is an image hanging on the signer and its value row
     * carries only the fact that it happened, so the picture is fetched by
     * address while a typed one travels as text. See ContractFieldType.
     *
     * @return array<string, mixed>|null
     */
    private function filledBox(
        ContractSigner $reader,
        ContractField $field,
        ContractSigner $other,
        ?ContractFieldValue $value,
    ): ?array {
        $box = [
            'id' => $field->id,
            'page' => $field->page,
            'x' => (float) $field->x,
            'y' => (float) $field->y,
            'width' => (float) $field->width,
            'height' => (float) $field->height,
            'type' => $field->type->value,
        ];

        if ($field->type->isDrawn()) {
            if ($value === null || $other->mark($field->type) === null) {
                return null;
            }

            return [...$box, 'value' => null, 'mark' => $this->otherMarkUrl($reader, $other, $field->type)];
        }

        /*
         * An empty answer is drawn as nothing rather than as an empty box. The
         * boxes on the page belong to the document; a blank one from somebody
         * who has already signed says only that they left it blank, and a grey
         * rectangle saying that over the paragraph it sits on is noise.
         */
        if ($value === null || $value->value === null || $value->value === '') {
            return null;
        }

        return [...$box, 'value' => $value->value, 'mark' => null];
    }

    /**
     * The address of somebody else's mark, reached with this reader's own
     * token.
     *
     * Never the other person's token, which is the one rule this route exists
     * to keep: their token is permission to sign as them, and a signature
     * image on a page is not.
     */
    private function otherMarkUrl(ContractSigner $reader, ContractSigner $other, ContractFieldType $type): string
    {
        $media = $other->mark($type);

        return route('contracts.sign.mark.show', [
            'token' => $reader->token,
            'signer' => $other->id,
            'kind' => $type->value,
            'v' => $media?->updated_at?->timestamp,
        ]);
    }

    /**
     * The address of one of this signer's marks, with the moment it was made
     * hung on the end so a browser cannot serve a replaced one from cache.
     */
    private function markUrl(ContractSigner $signer, ContractFieldType $type): string
    {
        $media = $signer->mark($type);

        return route('contracts.sign.signature.show', [
            'token' => $signer->token,
            'kind' => $type->value,
            'v' => $media?->updated_at?->timestamp,
        ]);
    }
}
