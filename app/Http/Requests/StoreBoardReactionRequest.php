<?php

namespace App\Http\Requests;

use App\Models\BoardPost;
use App\Rules\ReactionEmoji;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardReactionRequest extends FormRequest
{
    /**
     * Reacting asks the same question reading does, and no more.
     *
     * Not `comment`, even though the two happen in the same place: replying is
     * writing something under somebody's notice, reacting is the gesture people
     * make instead — and a board where you may only agree by writing a
     * paragraph is a board with no agreement on it.
     */
    public function authorize(): bool
    {
        return $this->user()->can('react', $this->route('board_post'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var BoardPost $post */
        $post = $this->route('board_post');

        return [
            // The same rule the channel reactions use — the same object now,
            // rather than the same lines copied: a pill is a symbol or one of
            // this workspace's own pictures, and never a label.
            'emoji' => ['required', 'string', 'max:32', new ReactionEmoji($post->workspace_id)],
        ];
    }
}
