<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesAttachments;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    use ValidatesAttachments;

    /**
     * A reply is judged by a different rule than a new message: a channel that
     * only admins may post in still lets everyone answer in a thread.
     *
     * prepareForValidation() has already run at this point, so parent_id is
     * normalised — an empty string here would otherwise read as a reply.
     */
    public function authorize(): bool
    {
        $ability = $this->input('parent_id') === null ? 'post' : 'reply';

        return $this->user()->can($ability, $this->route('channel'));
    }

    /**
     * Ids are compared as strings to work out what a member has already read,
     * and PHP compares them byte for byte — where every uppercase letter sorts
     * before every lowercase one. Laravel stores lowercase ULIDs, so anything
     * arriving in another case would compare as older than every existing
     * message. Normalise on the way in rather than trusting the client.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'id' => $this->string('id')->lower()->value(),
            'parent_id' => $this->string('parent_id')->lower()->value() ?: null,
            'quoted_message_id' => $this->string('quoted_message_id')->lower()->value() ?: null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        $workspace = $channel->workspace;

        return [
            'id' => ['required', 'string', 'size:26', 'ulid', Rule::unique('messages', 'id')],

            // A file on its own is a message. Words on their own still are too,
            // so what is required is that at least one of them shows up.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:4000'],

            // See ValidatesAttachments: the same rules a ticket comment gets,
            // because they are the workspace's rules rather than the message's.
            ...$this->attachmentRules($workspace),
            'parent_id' => [
                'nullable',
                'ulid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $channel->id)
                    ->whereNull('parent_id'),
            ],
            /*
             * The channel check is a boundary, not a tidiness rule: the quoted
             * text is rendered back to everyone in this channel, so without it
             * a member could lift a line out of a conversation they were never
             * part of. Unlike parent_id this may point at a thread reply — you
             * can quote anything you can read here.
             */
            'quoted_message_id' => [
                'nullable',
                'ulid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $channel->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => __('requests.message.parent_not_here'),
            'quoted_message_id.exists' => __('requests.message.quote_not_here'),
            'body.required_without' => __('requests.message.empty'),
            ...$this->attachmentMessages(),
        ];
    }
}
