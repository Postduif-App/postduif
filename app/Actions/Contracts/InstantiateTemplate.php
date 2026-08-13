<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Make a real contract out of a template.
 *
 * The difference from DuplicateContract, which does something that looks the
 * same, is the one thing this copies and that one refuses to: the author's
 * signature. Duplicating deliberately leaves every trace of what happened to
 * the original behind, because carrying it across would be claiming somebody
 * signed a document they have never seen. Here the author signed the template
 * precisely so that they would not have to sign each of the hundred contracts
 * made from it — the claim is theirs, made once, knowingly, about a document
 * that is copied byte for byte.
 *
 * Which is what makes the hash worth so much here. The copy points at the same
 * bytes and keeps the same source_hash, so the author's signature on the copy
 * covers exactly the document they put it under. Re-normalising the PDF would
 * produce a different file and quietly break that link — see the note in
 * DuplicateContract, which has the same reason for the same decision.
 *
 * Nothing is sent from here. What comes out is a draft with one party already
 * settled and room for the rest; SendContract is what puts it in the post, and
 * it is handed the roster this action builds.
 */
class InstantiateTemplate
{
    /**
     * @param  Contract  $template  Left exactly as it was, signature and all.
     * @param  User  $author  Whoever is sending this one. Usually the person who
     *                        made the template, but not always — an API token
     *                        belongs to somebody, and it is their contract now.
     * @param  string|null  $title  What to call it. Null keeps the template's
     *                              own title, which is the ordinary case: a
     *                              lease sent through an API has no better name
     *                              to be given by a machine.
     */
    public function handle(Contract $template, User $author, ?string $title = null): TemplateInstance
    {
        if (! $template->is_template) {
            throw new RuntimeException('Only a template can be instantiated.');
        }

        $template->loadMissing(['fields', 'signers']);

        if (! $template->isReadyToSend()) {
            throw new RuntimeException('This template is not finished being prepared.');
        }

        $source = $template->source();

        if ($source === null) {
            throw new RuntimeException('A template without its document cannot be used.');
        }

        return DB::transaction(function () use ($template, $author, $title, $source): TemplateInstance {
            $copy = Contract::create([
                'workspace_id' => $template->workspace_id,
                'created_by' => $author->id,
                'title' => $title ?? $template->title,
                'message' => $template->message,
                'status' => ContractStatus::Draft,
                'is_template' => false,
                'page_count' => $template->page_count,
                'source_hash' => $template->source_hash,
            ]);

            /*
             * The boxes, geometry, ownership and all.
             *
             * signer_index rides across untouched, and it lands correctly
             * because a template numbers its parties the way the copy will:
             * the author at zero when they sign along, the recipients from one
             * upwards. The map from old field to new is kept because the
             * author's answers point at these rows and have to follow.
             */
            $fields = [];

            foreach ($template->fields as $field) {
                $fields[$field->id] = $copy->fields()->create([
                    'page' => $field->page,
                    'x' => $field->x,
                    'y' => $field->y,
                    'width' => $field->width,
                    'height' => $field->height,
                    'type' => $field->type,
                    'label' => $field->label,
                    'is_required' => $field->is_required,
                    'position' => $field->position,
                    'signer_index' => $field->signer_index,
                ]);
            }

            $this->carryTheAuthorAcross($template, $copy, $fields);

            /*
             * The PDF, copied on the disk rather than referenced — two rows
             * pointing at one file would mean deleting either contract takes
             * the document out from under the other, and here the other is a
             * template that a hundred more contracts are still to come from.
             *
             * Last inside the transaction, so a failure anywhere above leaves
             * no stray file behind.
             */
            $source->copy($copy, Contract::SOURCE);

            return new TemplateInstance(
                contract: $copy->fresh(['fields', 'signers']),
                fields: array_map(fn (ContractField $field): int => $field->id, $fields),
            );
        });
    }

    /**
     * Who this contract is for, in the order the boxes expect them.
     *
     * Built here rather than by whoever is sending, because the offset is this
     * action's secret: a template the author signed along with has them at
     * position zero, and the recipients have to start at one or every box on
     * the page shifts by a person. Handing SendContract a list that starts with
     * the author is also what keeps their row — SaveContractSigners matches on
     * the address, and a name missing from the list is a signer it removes.
     *
     * @param  list<array{name: string, email: string, user_id?: int|null}>  $recipients
     * @return list<array{name: string, email: string, user_id?: int|null}>
     */
    public function roster(Contract $contract, array $recipients): array
    {
        $author = $contract->signers->first(
            fn (ContractSigner $signer): bool => $signer->signing_order === 0 && $signer->hasSigned()
        );

        if ($author === null) {
            return $recipients;
        }

        return [
            ['name' => $author->name, 'email' => $author->email, 'user_id' => $author->user_id],
            ...$recipients,
        ];
    }

    /**
     * Copy the author's row, their mark and everything they filled in.
     *
     * The row is rebuilt rather than replicated wholesale, and the two columns
     * left behind say why. The token is fresh because a token is a credential
     * for one contract, and reusing the template's would mean one link that
     * opens every contract ever made from it. copy_sent_at is dropped because
     * nobody has been sent anything yet — keeping it would tell
     * RenderSignedContractJob that this author already has their copy of a
     * document that does not exist.
     *
     * signed_document_hash comes across unchanged, which is the whole point:
     * it is the measurement of the file this contract points at, and that file
     * is the same file.
     *
     * @param  array<int, ContractField>  $fields  Old field id to its copy.
     */
    private function carryTheAuthorAcross(Contract $template, Contract $copy, array $fields): void
    {
        $author = $template->signers->first();

        if ($author === null) {
            return;
        }

        $signer = $copy->signers()->create([
            'token' => ContractSigner::freshToken(),
            'user_id' => $author->user_id,
            'name' => $author->name,
            'email' => $author->email,
            'signing_order' => 0,
        ]);

        /*
         * forceFill for the evidence columns, the way StoreSignature does. They
         * are kept out of the fillable list on purpose — they are written by
         * the actions that own the moment somebody signs, never by anything
         * that has been near a request — and this is one of those actions,
         * carrying a signature that was made under exactly these bytes.
         */
        $signer->forceFill([
            'opened_at' => $author->opened_at,
            'signed_at' => $author->signed_at,
            'signed_document_hash' => $author->signed_document_hash,
            'signature_method' => $author->signature_method,
            'signature_text' => $author->signature_text,
            'ip_address' => $author->ip_address,
            'user_agent' => $author->user_agent,
        ])->save();

        /*
         * The drawing itself, copied on the disk for the same reason the PDF
         * is: the media library removes a file on the model's delete event
         * without asking who else points at it, and the template has to survive
         * every contract made from it being thrown away.
         */
        foreach ([ContractSigner::SIGNATURE, ContractSigner::INITIALS] as $collection) {
            $author->getFirstMedia($collection)?->copy($signer, $collection);
        }

        /*
         * What they typed into their own boxes — a date, a name, a reference.
         * Without these the copy would carry the signature and lose everything
         * around it, which on a page laid out as one statement reads as a
         * signature under a blank.
         */
        ContractFieldValue::query()
            ->where('contract_signer_id', $author->id)
            ->get()
            ->each(function (ContractFieldValue $value) use ($fields, $signer): void {
                $field = $fields[$value->contract_field_id] ?? null;

                if ($field === null) {
                    return;
                }

                $signer->values()->create([
                    'contract_field_id' => $field->id,
                    'value' => $value->value,
                    'filled_at' => $value->filled_at,
                ]);
            });
    }
}
