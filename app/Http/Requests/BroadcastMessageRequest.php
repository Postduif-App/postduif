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

            /*
             * Absent means now. A moment in the past is refused rather than
             * quietly sent immediately: somebody who typed yesterday's date
             * made a mistake, and sending anyway would hide it.
             */
            'send_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('requests.broadcast.body_required'),
            'channels.required_without' => __('requests.broadcast.no_target'),
            'tags.required_without' => __('requests.broadcast.no_target'),
            'send_at.after' => __('requests.broadcast.send_at_past'),
        ];
    }
}
