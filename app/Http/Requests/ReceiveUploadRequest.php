<?php

namespace App\Http\Requests;

use App\Actions\UploadLinks\ResolveUploadLink;
use App\Models\UploadLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * What a stranger may hand in through an upload link.
 *
 * Anybody holding the link reaches this, so every limit on it is a limit on
 * what somebody we do not know may put on our disk.
 */
class ReceiveUploadRequest extends FormRequest
{
    /** More than this is a folder, and a folder travels better as one archive. */
    private const MAX_FILES = 50;

    private ?UploadLink $resolved = null;

    /**
     * The token is the permission, and the password when there is one.
     *
     * Asked here rather than in the controller because a form request is
     * validated before the controller runs, and a locked link must not even
     * tell a visitor which of their files were too large. A 403, not a 404:
     * the visitor has already been shown this link exists.
     */
    public function authorize(): bool
    {
        return app(ResolveUploadLink::class)->isUnlocked($this, $this->uploadLink());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],

            /*
             * No mimetypes rule, as on a transfer. People send in what they
             * have — a scan, a spreadsheet, a zip of last year's books — and
             * what makes that safe is on the way out: these files only ever
             * leave again as an attachment, with nosniff, to somebody signed in.
             */
            'files.*' => ['file', 'max:'.$this->uploadLink()->workspace->max_transfer_kb],

            // Who it is from, in their own words. Required: a pile of files
            // with no name on it is a pile nobody can thank anybody for.
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The workspace's ceiling is on everything this link has taken in, not on
     * one submission. Otherwise an open link with no upload limit is a way for
     * anybody holding it to fill the disk, fifty files at a time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['files', 'files.*'])) {
                return;
            }

            $link = $this->uploadLink();

            $incoming = array_sum(array_map(
                fn (UploadedFile $file): int => (int) $file->getSize(),
                $this->file('files', []),
            ));

            if ($link->receivedBytes() + $incoming > $link->workspace->max_transfer_kb * 1024) {
                $validator->errors()->add('files', __('requests.upload_link.too_large_together'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => __('requests.upload_link.files_required'),
            'files.max' => __('requests.transfer.too_many_files', ['count' => self::MAX_FILES]),
            'files.*.max' => __('requests.transfer.file_too_large'),
            'email.email' => __('requests.transfer.invalid_email'),
        ];
    }

    /** The link this token stands for, looked up once — or a 404. */
    public function uploadLink(): UploadLink
    {
        return $this->resolved ??= app(ResolveUploadLink::class)->handle((string) $this->route('token'));
    }
}
