<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a notice, or moving it to the top of the board.
 *
 * Two things through one request because they arrive from the same screen, and
 * they authorise separately: pinning is a beheerder's, editing is the author's.
 * The controller asks per field rather than this class asking once — see
 * BoardPostController::update.
 */
class UpdateBoardPostRequest extends FormRequest
{
    /**
     * Left to the controller, which is the only place that knows which of the
     * two abilities this particular request needs. Returning true here is not a
     * hole: nothing has been authorised yet, and every branch below it asks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // required_with rather than required: a request that only pins does
            // not carry them, and one that edits must carry both — a notice
            // with a title and no text is not a correction, it is a mistake.
            'title' => ['required_with:body', 'string', 'max:120'],
            'body' => ['required_with:title', 'string', 'max:8000'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
