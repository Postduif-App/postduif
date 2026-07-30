<?php

namespace App\Http\Requests;

use App\Enums\ChannelType;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $workspace->hasMember($this->user());
    }

    /**
     * Names are slugged before they are validated, so "Nieuwe Klanten" and
     * "nieuwe-klanten" collide the way a member expects them to rather than
     * quietly becoming two channels with the same address.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => Str::slug((string) $this->input('name'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('channels', 'slug')->where('workspace_id', $workspace->id),
            ],
            'type' => ['required', new Enum(ChannelType::class), Rule::notIn([ChannelType::Direct->value])],
            'topic' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Geef het kanaal een naam.',
            'name.unique' => 'Er bestaat al een kanaal met deze naam.',
            'type.not_in' => 'Een direct bericht maak je niet aan als kanaal.',
        ];
    }
}
