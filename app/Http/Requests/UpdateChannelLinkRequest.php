<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesChannelLinkTarget;
use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelLinkRequest extends FormRequest
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
            'label' => ['sometimes', 'required', 'string', 'max:40'],
            /*
             * Partial, because the panel saves a button at a time and renaming
             * one says nothing about where it points.
             *
             * Note what these rules cannot see: they read the request, not the
             * row, so a request carrying only workflow_id looks fine here while
             * the stored url is still sitting beside it. Clearing the other
             * column is the controller's job — see ChannelLinkController.
             */
            ...$this->targetRules($channel instanceof Channel ? $channel : null, partial: true),
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
