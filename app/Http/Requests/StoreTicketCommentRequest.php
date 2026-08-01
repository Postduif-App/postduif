<?php

namespace App\Http\Requests;

use App\Concerns\ValidatesAttachments;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketCommentRequest extends FormRequest
{
    use ValidatesAttachments;

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

        $workspace = $ticket->channel?->workspace;

        return [
            // A file on its own is a reply. Words on their own still are too,
            // so what is required is that at least one of them shows up — the
            // same rule a message follows.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:4000'],

            // The workspace's own rules, shared with the message request so the
            // two cannot come to disagree about what may be sent.
            ...($workspace === null ? [] : $this->attachmentRules($workspace, max: 5)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->attachmentMessages(max: 5),
            'body.required_without' => 'Schrijf iets, of stuur een bestand mee.',
        ];
    }
}
