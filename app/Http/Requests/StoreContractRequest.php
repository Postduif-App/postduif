<?php

namespace App\Http\Requests;

use App\Models\Contract;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    /**
     * Starting a contract is its own right — see WorkspaceAbility::SendContracts.
     *
     * Whether the workspace does contracts at all is asked by the middleware on
     * the route rather than here, and the two are kept apart on purpose: "deze
     * workspace doet dit niet" and "jij mag dit niet" are different answers and
     * deserve different screens.
     */
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $this->user()->can('create', [Contract::class, $workspace]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],

            /*
             * A deadline is optional but bounded. Nothing breaks at a year, but
             * a link that opens a signable document five years from now is a
             * loose end nobody will remember leaving, and the deadline is what
             * eventually hands the disk space back.
             */
            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            'file' => [
                'required',
                'file',
                'max:'.(int) config('contracts.max_upload_kilobytes'),

                /*
                 * mimetypes rather than mimes: the first reads the file's own
                 * bytes, the second trusts the extension. That distinction
                 * matters more here than anywhere else in the application,
                 * because this file is going to be mailed to people outside the
                 * workspace with "hier moet je tekenen" beside it.
                 *
                 * And it is still only the first of three gates. NormalisePdf
                 * checks the header, rewrites the file through Ghostscript, and
                 * then refuses it if anything executable survived — see there
                 * for why a mime type on its own settles nothing.
                 */
                'mimetypes:application/pdf',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => __('contracts.upload.not-a-pdf'),
            'file.max' => __('contracts.upload.too-large', [
                'max' => round(((int) config('contracts.max_upload_kilobytes')) / 1024),
            ]),
        ];
    }
}
