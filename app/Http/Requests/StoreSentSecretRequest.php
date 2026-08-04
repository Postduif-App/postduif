<?php

namespace App\Http\Requests;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSentSecretRequest extends FormRequest
{
    /** A month, the same ceiling a request gets and for the same reason. */
    private const MAX_VALID_DAYS = 30;

    public function authorize(): bool
    {
        $channel = $this->route('channel');

        /*
         * The same two questions StoreSecretRequestRequest asks, and the same
         * reasoning: handing over a secret is a workspace-level ability, and
         * announcing it in this particular channel is posting in it.
         */
        return $this->user()->can('createSecretRequest', $this->route('workspace'))
            && $channel instanceof Channel
            && $this->user()->can('post', $channel);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return [
            // Said in the open, in the channel, so it must never be the secret
            // itself. Short enough that nobody mistakes it for a place to put
            // one.
            'label' => ['required', 'string', 'max:120'],

            /*
             * Must be somebody in this channel. Not tidiness: the card names
             * them in front of everybody, so an arbitrary user id here would be
             * a way to announce "er staat iets klaar voor Fenna" in a room Fenna
             * is not in and cannot correct.
             */
            'recipient_id' => [
                'required',
                'integer',
                Rule::exists('channel_user', 'user_id')
                    ->where('channel_id', $channel instanceof Channel ? $channel->id : 0),
            ],

            /*
             * Already encrypted, and never inspected here. Capped so the column
             * cannot be used as free storage — an AES-GCM payload of a password
             * or a key is a fraction of this.
             */
            'ciphertext' => ['required', 'string', 'max:20000'],

            // Base64 of a 12-byte AES-GCM nonce, which is 16 characters. Pinned
            // rather than loose: a wrong length here means the browser sending
            // it is not the one we wrote.
            'iv' => ['required', 'string', 'size:16'],

            /*
             * The optional second gate. No complexity rules — this is a
             * throwaway passed over the phone, and demanding a capital letter
             * for it teaches people to write it down instead.
             */
            'password' => ['nullable', 'string', 'min:4', 'max:200'],

            'valid_for_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_VALID_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => __('requests.secret.label_required'),
            'recipient_id.required' => __('requests.secret.recipient_required'),
            'recipient_id.exists' => __('requests.secret.recipient_not_in_channel'),
            'valid_for_days.max' => __('requests.secret.stored_too_long', ['days' => self::MAX_VALID_DAYS]),
        ];
    }
}
