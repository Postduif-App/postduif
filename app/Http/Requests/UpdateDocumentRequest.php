<?php

namespace App\Http\Requests;

use App\Rules\DocumentBody;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * The version the browser was handed when it opened the document.
             * Required rather than optional: a save without one is a save that
             * cannot be checked for conflicts, and quietly letting those
             * through would make the whole mechanism decorative.
             */
            'version' => ['required', 'integer', 'min:1'],

            // Either half may be absent — a rename ships no document, and
            // autosave ships no title.
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body' => ['sometimes', 'array', new DocumentBody],

            /*
             * The flattened text, and it travels with the document rather than
             * on its own: it is the same content seen from another angle, and
             * one without the other leaves the search index describing a
             * version that no longer exists. required_with rather than a check
             * in the action, so the caller is told which field is missing.
             *
             * The length ceiling is well above the document's own, because
             * plain text is smaller than the JSON it came out of; this is here
             * to bound the column, not to judge the writing.
             */
            'body_text' => ['required_with:body', 'string', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('requests.documents.title_required'),
            'body_text.required_with' => __('requests.documents.text_with_body'),
        ];
    }
}
