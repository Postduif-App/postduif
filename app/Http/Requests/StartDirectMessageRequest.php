<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartDirectMessageRequest extends FormRequest
{
    /**
     * Whether this member may start conversations at all. Whether they may
     * start one with this particular person is a second question, asked in the
     * controller once the recipient has been resolved — a guest addressing
     * somebody outside their channels gets refused there.
     */
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $this->user()->can('startDirectMessage', $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return [
            'user_id' => [
                'required',
                'integer',
                // Membership of this workspace is a validation rule rather than
                // a policy check: somebody who does not belong here is not a
                // person you were refused, it is an id that means nothing.
                Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => __('requests.direct_message.recipient_required'),
            'user_id.exists' => __('requests.direct_message.not_a_member'),
        ];
    }
}
