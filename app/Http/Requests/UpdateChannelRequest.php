<?php

namespace App\Http\Requests;

use App\Enums\ChannelLayout;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\ChannelType;
use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('channel'));
    }

    /**
     * Slugged before validation, exactly as when the channel was created: two
     * spellings of one name must collide here for the same reason they do
     * there, or renaming would be the way around the uniqueness rule.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => Str::slug((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        return [
            /*
             * Both optional, and both prohibited on a DM: that one is labelled
             * with the people in it, so a name stored on it would be a name
             * nobody ever sees. Ignoring the channel itself in the uniqueness
             * rule is what makes saving the form without touching the name work
             * at all.
             */
            'name' => [
                'sometimes',
                Rule::prohibitedIf($channel->isDirect()),
                'required',
                'string',
                'max:80',
                Rule::unique('channels', 'slug')
                    ->where('workspace_id', $channel->workspace_id)
                    ->ignore($channel),
            ],
            'topic' => [
                'sometimes',
                Rule::prohibitedIf($channel->isDirect()),
                'nullable',
                'string',
                'max:255',
            ],

            /*
             * Open or private, and nothing else: a DM is not a visibility a
             * channel can be talked into, it is a conversation that was started
             * between two people.
             */
            'type' => [
                'sometimes',
                Rule::prohibitedIf($channel->isDirect()),
                new Enum(ChannelType::class),
                Rule::notIn([ChannelType::Direct->value]),
            ],

            /*
             * How the channel reads. Prohibited on a DM for the same reason the
             * name is: a two-person conversation has no other shape to take.
             */
            'layout' => [
                'sometimes',
                Rule::prohibitedIf($channel->isDirect()),
                new Enum(ChannelLayout::class),
            ],

            'posting_policy' => ['required', new Enum(ChannelPostingPolicy::class)],
            'replies_open' => ['sometimes', 'boolean'],
            // Optional, unlike the posting policy above: this dialog is not the
            // only thing that patches a channel, and a caller that says nothing
            // about tickets should leave them exactly as they were rather than
            // silently switching them off.
            'ticket_policy' => ['sometimes', new Enum(ChannelTicketPolicy::class)],
            'ticket_announcements' => ['sometimes', 'boolean'],
            'ticket_status_announcements' => ['sometimes', 'boolean'],
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
            'name.prohibited' => 'Een direct bericht heeft geen naam.',
            'topic.prohibited' => 'Een direct bericht heeft geen onderwerp.',
            'type.prohibited' => 'De zichtbaarheid van een direct bericht ligt vast.',
            'layout.prohibited' => 'Een direct bericht heeft geen andere weergave.',
            'type.not_in' => 'Een direct bericht maak je niet van een kanaal.',
            'posting_policy.enum' => 'Kies een geldige instelling.',
            'ticket_policy.enum' => 'Kies een geldige instelling.',
        ];
    }
}
