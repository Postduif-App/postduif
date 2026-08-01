<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BroadcastMessageRequest extends FormRequest
{
    /**
     * Whether this member may address the workspace at all — not a guest.
     *
     * Which of the chosen channels it actually reaches is a second question,
     * decided per channel in BroadcastToChannels: the answer differs per
     * channel, and refusing the whole request over one of them would be the
     * wrong shape.
     */
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return $this->user()->can('broadcastToChannels', $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
            /*
             * Channels and tags are both optional on their own but not
             * together: picking neither is asking to send this nowhere. The
             * rule says so with required_without rather than a check in the
             * controller, so the message lands on the fields it is about.
             */
            'channels' => ['array', 'required_without:tags'],
            'channels.*' => ['integer'],
            'tags' => ['array', 'required_without:channels'],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Schrijf eerst een bericht.',
            'channels.required_without' => 'Kies minstens één kanaal of tag.',
            'tags.required_without' => 'Kies minstens één kanaal of tag.',
        ];
    }
}
