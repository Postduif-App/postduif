<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUploadLinkRequest extends FormRequest
{
    /**
     * The same right as sending files out. Both put a door in the workspace
     * that people without an account may use, and a workspace that trusts a
     * role with one has, so far, trusted it with the other.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('createTransfer', $this->route('workspace'))) {
            return false;
        }

        $channel = $this->notifyChannel();

        /*
         * The news is posted as a bot line in that channel, so naming one is
         * deciding what gets said there. Not a way into a channel you may not
         * write in.
         */
        return $channel === null || $this->user()->can('post', $channel);
    }

    /** The channel to tell when something comes in, when one was named. */
    public function notifyChannel(): ?Channel
    {
        $id = $this->input('notify_channel_id');

        // Shape-checked rather than trusted, because authorize() runs before
        // the rules do — see StoreTransferRequest::announcementChannel().
        if (! is_numeric($id)) {
            return null;
        }

        return Channel::query()
            ->where('workspace_id', $this->uploadWorkspace()->id)
            ->find((int) $id);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->uploadWorkspace();

        return [
            // Required: there are no files yet to say what this is about.
            'title' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
            'valid_for_days' => ['required', 'integer', 'min:1', 'max:'.$workspace->max_transfer_days],
            'max_uploads' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // The same floor as a transfer password, for the same reason.
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'notify_channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valid_for_days.max' => __('requests.transfer.valid_too_long', [
                'days' => $this->uploadWorkspace()->max_transfer_days,
            ]),
        ];
    }

    private function uploadWorkspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
