<?php

namespace App\Models;

use Database\Factories\FormSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's filled-in form.
 *
 * @property string $id
 * @property string $form_id
 * @property int|null $submitted_by
 * @property bool $via_link
 * @property Carbon|null $created_at
 * @property-read User|null $submitter The person who sent it in, or nobody:
 *     an anonymous submission over the public link, or a leaver whose account
 *     has since gone.
 * @property-read Form $form
 */
#[Fillable(['form_id', 'submitted_by', 'via_link'])]
class FormSubmission extends Model
{
    /** @use HasFactory<FormSubmissionFactory> */
    use HasFactory, HasUlids;

    /** Nothing about a submission changes after it is sent in. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['via_link' => 'boolean'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class)->orderBy('position');
    }

    /**
     * Whether nobody's name is attached to this.
     *
     * Asked rather than compared against submitted_by in four places, because
     * "we do not know who this was" is the fact the screens and the DM both
     * have to say out loud.
     */
    public function isAnonymous(): bool
    {
        return $this->submitted_by === null;
    }

    /**
     * The answers as key => readable value, which is what a workflow reads as
     * {{ trigger.answers.reden }}.
     *
     * @return array<string, string>
     */
    public function keyedAnswers(): array
    {
        return $this->answers
            ->mapWithKeys(fn (FormAnswer $answer): array => [$answer->field_key => $answer->display()])
            ->all();
    }
}
