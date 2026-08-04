<?php

namespace App\Http\Requests;

use App\Models\BoardPost;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [BoardPost::class, $this->route('workspace')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * Shorter than a ticket's 160 on purpose. This is the line the whole
             * workspace scans down, and a title that needs two lines to fit is
             * one that is trying to be the notice itself.
             */
            'title' => ['required', 'string', 'max:120'],

            // Longer than a ticket's, though. A notice is read once and has to
            // say everything: no thread underneath fills in what it left out.
            'body' => ['required', 'string', 'max:8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('requests.board_post.title_required'),
            'body.required' => __('requests.board_post.body_required'),
        ];
    }
}
