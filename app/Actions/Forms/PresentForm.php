<?php

namespace App\Actions\Forms;

use App\Models\Form;

/**
 * A form in the shape the screens draw it.
 *
 * One presenter for three audiences — the member filling it in from a channel,
 * the stranger who followed the link, and the card in the conversation — so
 * that a question cannot look required on one and optional on another.
 *
 * Note what never travels: created_by, workspace_id, the share token, or
 * anything about who else answered. A public page renders this array, and the
 * safe way to keep a stranger from learning who is in this workspace is not to
 * send it.
 */
class PresentForm
{
    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     description: string|null,
     *     author: string|null,
     *     state: string,
     *     isFillable: bool,
     *     closesAt: string|null,
     *     fields: list<array{key: string, type: string, label: string, hint: string|null, required: bool, options: list<string>}>
     * }
     */
    public function handle(Form $form): array
    {
        $form->loadMissing(['fields', 'author:id,name']);

        return [
            'id' => $form->id,
            'title' => $form->title,
            'description' => $form->description,

            // A name, never an id: the fill screen says who is asking, and on
            // the public page that is somebody the visitor may not look up.
            'author' => $form->author?->name,

            'state' => match (true) {
                $form->closed_at !== null => 'closed',
                $form->isClosed() => 'expired',
                default => 'open',
            },

            // Two facts rather than one, because "closed" and "has no
            // questions" read differently to whoever opened the page.
            'isFillable' => $form->acceptsAnswers(),

            'closesAt' => $form->closes_at?->toIso8601String(),

            'fields' => $this->fields($form),
        ];
    }

    /**
     * The questions, in the order they are asked.
     *
     * @return list<array{key: string, type: string, label: string, hint: string|null, required: bool, options: list<string>}>
     */
    private function fields(Form $form): array
    {
        $fields = [];

        foreach ($form->fields as $field) {
            $fields[] = [
                'key' => $field->key,
                'type' => $field->type->value,
                'label' => $field->label,
                'hint' => $field->hint,
                'required' => $field->required,
                'options' => $field->options,
            ];
        }

        return $fields;
    }
}
