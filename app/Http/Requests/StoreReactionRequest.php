<?php

namespace App\Http\Requests;

use App\Models\Channel;
use App\Rules\ReactionEmoji;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReactionRequest extends FormRequest
{
    /**
     * Reacting needs membership — reading a public channel is open to the whole
     * workspace, leaving something behind is not — but not permission to post:
     * in a channel only admins may write in, everyone may still react.
     */
    public function authorize(): bool
    {
        return $this->user()->can('react', $this->route('channel'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        return [
            // What may be in it is ReactionEmoji's business — a pill is a
            // symbol or a picture this workspace uploaded, never a label. The
            // length is the column's, and a name is capped at thirty so that
            // ":name:" always fits inside it.
            'emoji' => ['required', 'string', 'max:32', new ReactionEmoji($channel->workspace_id)],
        ];
    }
}
