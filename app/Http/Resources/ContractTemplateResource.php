<?php

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\ContractField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template as the system about to send it needs to see it.
 *
 * Everything here answers one of two questions a caller has: may I send this
 * one, and what do I have to put in the call. Nothing else is offered — not
 * the document, not who prepared it, not the author's signature. A template is
 * a workspace's own paperwork, and this is the outside of it.
 *
 * The boxes are listed per party rather than as one flat list, because that is
 * the shape the send call takes: the recipients arrive as an ordered list, and
 * a caller filling anything in ahead of time has to know which of them a box
 * belongs to. Only the boxes somebody can type into appear — a signature is
 * drawn by the person signing and can never be sent in.
 *
 * @property Contract $resource
 */
class ContractTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'message' => $this->resource->message,

            /*
             * How many recipients the send call must carry — not how many
             * parties the contract has. The author counts as a party when they
             * signed the template along, and they are already on it; asking a
             * caller to include somebody who has signed would be asking them to
             * know that.
             */
            'requiredSigners' => $this->resource->required_signers,
            'partyCount' => $this->resource->partyCount(),

            /*
             * Whether the author's signature is already on it. Worth saying out
             * loud rather than leaving to be inferred from the counts, because
             * it is the fact that explains the offset in the party numbering
             * below.
             */
            'preSigned' => $this->resource->templateSigner()?->hasSigned() ?? false,

            /*
             * The one field a caller should branch on. False means somebody in
             * the workspace still has to finish preparing it, and the send call
             * will refuse — see Contract::isReadyToSend for the four ways that
             * happens.
             */
            'readyToSend' => $this->resource->isReadyToSend(),

            'pageCount' => $this->resource->page_count,
            'parties' => $this->parties(),
            'createdAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * The fillable boxes, grouped by the party they were drawn for.
     *
     * Indexed by the position in the send call rather than by the raw
     * signer_index, so a caller never has to know about the author's offset:
     * recipient zero is the first name they pass, whether or not somebody
     * signed the template before them.
     *
     * @return list<array<string, mixed>>
     */
    private function parties(): array
    {
        // A template nobody has said the size of has no parties to describe.
        // Not an empty first recipient — that would read as "one, with no boxes".
        if ($this->resource->required_signers === null) {
            return [];
        }

        $offset = $this->resource->templateSigner() === null ? 0 : 1;

        $fields = $this->resource->fields
            ->reject(fn (ContractField $field): bool => $field->type->isDrawn())
            ->groupBy(fn (ContractField $field): int => $field->signerIndex());

        return array_values(collect(range(0, $this->resource->required_signers - 1))
            ->map(fn (int $recipient): array => [
                'recipient' => $recipient,
                'fields' => $fields->get($recipient + $offset, collect())
                    ->map(fn (ContractField $field): array => [
                        'id' => $field->id,
                        'label' => $field->label,
                        'type' => $field->type->value,
                        'required' => $field->is_required,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all());
    }
}
