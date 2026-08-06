<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesChannelLinkTarget;
use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChannelLinkRequest extends FormRequest
{
    use ValidatesChannelLinkTarget;

    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('channel'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return [
            'label' => ['required', 'string', 'max:40'],
            ...$this->targetRules($channel instanceof Channel ? $channel : null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => __('requests.channel_link.label_required'),
            'url.required_without' => __('requests.channel_link.url_required'),
            'url.url' => __('requests.channel_link.url_scheme'),
            'workflow_id.exists' => __('requests.channel_link.workflow_unknown'),
        ];
    }
}
