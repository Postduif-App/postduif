<?php

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contract as the system that sent it follows it.
 *
 * Named for the API rather than for the model, because the chat screen has its
 * own shape for the same row and the two are not the same promise: that one is
 * redrawn whenever the screen wants something new, this one is read by code in
 * somebody else's deployment and may not move.
 *
 * What it deliberately does not carry is any signer's token. That is the whole
 * credential for signing, and a caller who could read it could sign on behalf
 * of the person it was sent to — which would make every audit trail this
 * feature produces worth nothing. The link goes to the recipient by mail and
 * nowhere else.
 *
 * @property Contract $resource
 */
class ContractApiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'status' => $this->resource->status->value,

            /*
             * Where the finished document is up to, in its own field rather
             * than folded into the status. A completed contract whose PDF is
             * still being composed is a real state that lasts seconds and
             * occasionally forever — see Contract::signedCopyState, and the
             * document endpoint, which is what this field tells you to wait
             * for.
             */
            'signedCopy' => $this->resource->signedCopyState(),

            'signers' => $this->resource->signers
                ->map(fn (ContractSigner $signer): array => [
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'signedAt' => $signer->signed_at?->toIso8601String(),
                    'declinedAt' => $signer->declined_at?->toIso8601String(),
                    'declineReason' => $signer->decline_reason,

                    // Whether they have so much as opened it, which is the only
                    // thing there is to say about somebody who has not answered.
                    'openedAt' => $signer->opened_at?->toIso8601String(),
                ])
                ->values()
                ->all(),

            'expiresAt' => $this->resource->expires_at?->toIso8601String(),
            'completedAt' => $this->resource->completed_at?->toIso8601String(),
            'createdAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
