<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesAttachments;
use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentFileRequest extends FormRequest
{
    use ValidatesAttachments;

    /**
     * Putting a file in a document is writing in it.
     *
     * Judged by update rather than by anything of its own: a reader who may not
     * change a word should not be able to add a picture, and a writer who may
     * rewrite the whole page is not going to be stopped by an image.
     */
    public function authorize(): bool
    {
        /** @var Document $document */
        $document = $this->route('document');

        return $this->user()->can('update', $document);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Document $document */
        $document = $this->route('document');

        $workspace = $document->workspace;

        /*
         * One file rather than the array a message sends, because that is what
         * the editor does: a drop is one block, and a second picture is a
         * second request. So the rules are spelled out here instead of coming
         * from attachmentRules() — but the three things they are made of are
         * the workspace's own settings, read through the same trait, because a
         * workspace that takes no files takes none here either.
         */
        return [
            'file' => [
                'required',
                'file',
                'max:'.$workspace->max_attachment_kb,
                // Judged on the file's own bytes, not on its name: mimetypes
                // reads the content, where mimes would trust the extension.
                'mimetypes:'.implode(',', $this->acceptedMimeTypes($workspace)),
                Rule::prohibitedIf(fn (): bool => ! $workspace->uploads_enabled),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.prohibited' => __('requests.attachments.uploads_off'),
            'file.max' => __('requests.attachments.too_large'),
            'file.mimetypes' => __('requests.attachments.type_not_allowed'),
        ];
    }
}
