<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A secret link made from the secrets page, belonging to no channel.
 *
 * Its own request rather than optional fields on StoreSentSecretRequest, and the
 * difference is not cosmetic: that one must check that the recipient is in the
 * channel, because the card names them in front of everybody in it. There is no
 * channel here and no card, so that rule has nothing to stand on — and a shared
 * request would have had to make it conditional, which is how it eventually gets
 * skipped in the case that needed it.
 */
class StoreWorkspaceSecretRequest extends FormRequest
{
    /** A month, the same ceiling every other secret gets. */
    private const MAX_VALID_DAYS = 30;

    public function authorize(): bool
    {
        // One question rather than two: there is no channel to be allowed to
        // post in, so the workspace-level ability is the whole of it.
        return $this->user()->can('createSecretRequest', $this->route('workspace'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Same narrowing as StoreChannelRequest: the route binds this to a
        // model, which route()'s signature has no way of saying.
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');

        return [
            /*
             * Still required, and still said in the open — but the open is
             * narrower here. Nothing is posted anywhere, so this label is only
             * ever read back by the person who wrote it, in their own list. It
             * is what stops that list being twenty rows of "wachtwoord".
             */
            'label' => ['required', 'string', 'max:120'],

            /*
             * Optional, unlike the channel version. A link made here often goes
             * to somebody with no account at all — a customer, a supplier — and
             * demanding a name would mean inventing one.
             *
             * When it is given it must be somebody in this workspace, which is
             * the only thing left to check once the channel is gone.
             */
            'recipient_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspace->id),
            ],

            'ciphertext' => ['required', 'string', 'max:20000'],
            'iv' => ['required', 'string', 'size:16'],
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
            'recipient_id.exists' => __('requests.secret.recipient_not_in_workspace'),
            'valid_for_days.max' => __('requests.secret.stored_too_long', ['days' => self::MAX_VALID_DAYS]),
        ];
    }
}
