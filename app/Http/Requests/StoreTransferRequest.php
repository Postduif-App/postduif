<?php

namespace App\Http\Requests;

use App\Enums\TransferAudience;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
{
    /** More than this is a folder, and a folder travels better as one archive. */
    private const MAX_FILES = 50;

    /** Past this it is a mailing list, and a mailing list wants an open link. */
    private const MAX_RECIPIENTS = 50;

    public function authorize(): bool
    {
        if (! $this->user()->can('createTransfer', $this->route('workspace'))) {
            return false;
        }

        $channel = $this->announcementChannel();

        /*
         * Announcing in a channel is posting in it. Asked as its own question
         * rather than folded into the one above: being allowed to send files is
         * not being allowed to speak in every channel, and a transfer must not
         * become a way to put a line into a conversation you may not post in.
         */
        return $channel === null || $this->user()->can('post', $channel);
    }

    /** The channel this should be announced in, when there is one. */
    public function announcementChannel(): ?Channel
    {
        $id = $this->input('channel_id');

        /*
         * Checked for shape here rather than trusted, because authorize() runs
         * before the rules do: at this point channel_id is whatever arrived,
         * and an array would turn find() into a query for several rows.
         */
        if (! is_numeric($id)) {
            return null;
        }

        return Channel::query()
            ->where('workspace_id', $this->transferWorkspace()->id)
            ->find((int) $id);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->transferWorkspace();

        return [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],

            /*
             * No mimetypes rule, unlike an attachment — and that is the point of
             * the feature rather than an oversight. A transfer is for the file
             * that does not belong in a conversation: an archive, an installer,
             * a disk image. What makes that safe is on the way out, where the
             * download route hands everything over as an attachment and never
             * renders anything on our own origin.
             *
             * The per-file ceiling is the same number as the total, because a
             * single file is allowed to be the whole transfer.
             */
            'files.*' => ['file', 'max:'.$workspace->max_transfer_kb],

            /*
             * Who the link works for. Required rather than defaulted at this
             * layer: the default lives on the column, for links that were made
             * before the question existed, and a form that quietly picks the
             * widest option for somebody is the wrong way to answer "who may
             * use this".
             */
            'audience' => ['required', Rule::enum(TransferAudience::class)],

            /*
             * Required only for the audience that is a list of people. Asked
             * for at all times so a sender who switches back and forth in the
             * form does not lose what they typed, but only enforced where it
             * decides anything — an addressless "only these addresses" is not a
             * restriction, it is a transfer nobody can open.
             */
            'recipients' => [
                'array',
                'max:'.self::MAX_RECIPIENTS,
                Rule::requiredIf(
                    fn (): bool => $this->input('audience') === TransferAudience::NamedRecipients->value,
                ),
            ],
            'recipients.*' => ['email'],

            // Both optional: the files usually say enough by themselves.
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],

            /*
             * Required, and capped by the workspace. This is the one limit
             * without an "unbounded" option — see the transfers table for why
             * a link holding gigabytes may not live forever.
             */
            'valid_for_days' => ['required', 'integer', 'min:1', 'max:'.$workspace->max_transfer_days],

            // Absent means as often as anybody likes, which is a reasonable
            // thing to want for something sent to a whole team.
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:1000'],

            /*
             * Optional, and no rules about capitals or symbols. This is not an
             * account password: it is a word said over the phone or in a
             * separate message, and rules that make it hard to dictate would
             * push people towards not using one at all. The length floor is
             * there so it is not guessable in a handful of tries — the endpoint
             * that checks it is throttled for the rest.
             */
            'password' => ['nullable', 'string', 'min:6', 'max:255'],

            /*
             * The channel to announce it in, when this was started from a
             * message field rather than from the settings screen.
             *
             * Existence is checked here; whether this member may post there is
             * checked in authorize(), because that is a permission and not a
             * shape. Scoped to the workspace in the rule itself, so a channel
             * id from somewhere else cannot resolve.
             */
            'channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
        ];
    }

    /**
     * The ceiling the workspace set is on the lot, not on each file — otherwise
     * fifty files just under the limit would be fifty times the limit.
     *
     * Checked after the rules rather than inside them because it is a fact
     * about the request as a whole, and because a file that already failed its
     * own size rule should not be counted a second time in a second message.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['files', 'files.*'])) {
                return;
            }

            $ceiling = $this->transferWorkspace()->max_transfer_kb * 1024;

            $total = array_sum(array_map(
                fn (UploadedFile $file): int => (int) $file->getSize(),
                $this->file('files', []),
            ));

            if ($total > $ceiling) {
                $validator->errors()->add(
                    'files',
                    __('requests.transfer.too_large_together'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => __('requests.transfer.files_required'),
            'recipients.required' => __('requests.transfer.recipients_required'),
            'recipients.*.email' => __('requests.transfer.invalid_email'),
            'files.max' => __('requests.transfer.too_many_files', ['count' => self::MAX_FILES]),
            'files.*.max' => __('requests.transfer.file_too_large'),
            'valid_for_days.max' => __('requests.transfer.valid_too_long', [
                'days' => $this->transferWorkspace()->max_transfer_days,
            ]),
        ];
    }

    private function transferWorkspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
