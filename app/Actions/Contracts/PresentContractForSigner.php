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
        ];
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
