<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateTicketRequest extends FormRequest
{
    /**
     * The floor only: being in the channel this ticket lives in.
     *
     * Which of these fields somebody may actually touch differs per field — a
     * customer may confirm that their ticket is done but not decide it is
     * urgent — so the controller authorises each one it was asked to change.
     * Putting all of that here would mean a request that is refused as a whole
     * because one field in it was not allowed.
     */
    public function authorize(): bool
    {
        return $this->user()->can('comment', $this->route('ticket'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->route('ticket');

        return [
            'status' => ['sometimes', new Enum(TicketStatus::class)],
            'priority' => ['sometimes', new Enum(TicketPriority::class)],

            /*
             * Assignable to somebody in the channel, and to nobody. Anyone else
             * would be handed work in a place they cannot open — including a
             * workspace member who was never put in this channel.
             */
            'assigned_to' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('channel_user', 'user_id')->where('channel_id', $ticket->channel_id),
            ],

            'due_at' => ['sometimes', 'nullable', 'date'],
            'title' => ['sometimes', 'string', 'max:160'],
            'body' => ['sometimes', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.exists' => 'Die persoon zit niet in dit kanaal.',
        ];
    }
}
