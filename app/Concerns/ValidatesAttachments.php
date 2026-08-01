<?php

namespace App\Concerns;

use App\Models\Workspace;
use Illuminate\Validation\Rule;

/**
 * The rules a workspace puts on files, in one place.
 *
 * Shared rather than written twice, and the reason is not tidiness: these rules
 * are what a workspace's file settings actually mean. A second copy that
 * someone forgets to update is a way to send a file the workspace said no to —
 * the same reasoning that keeps the blocked-word filtering in one action.
 */
trait ValidatesAttachments
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function attachmentRules(Workspace $workspace, string $field = 'attachments', int $max = 10): array
    {
        return [
            /*
             * Ten at a time. Not a technical ceiling but a readability one: a
             * message carrying thirty files is a folder, and a folder is better
             * shared as one archive than as a wall of rows.
             */
            $field => [
                'array',
                'max:'.$max,
                Rule::prohibitedIf(fn (): bool => ! $workspace->uploads_enabled),
            ],
            $field.'.*' => [
                'file',
                'max:'.$workspace->max_attachment_kb,
                // Judged on the file's own bytes, not on its name: mimetypes
                // reads the content, where mimes would trust the extension.
                'mimetypes:'.implode(',', $this->acceptedMimeTypes($workspace)),
            ],
        ];
    }

    /**
     * The mime types this workspace takes, in the shape the validator wants.
     *
     * AttachmentType writes a whole family as "video/"; the mimetypes rule
     * spells the same thing "video/*". Translated here rather than stored that
     * way, because the trailing slash is what the enum's own matching uses.
     *
     * Never empty: an empty list would make the rule accept anything, which is
     * the opposite of what a workspace that allows nothing means.
     *
     * @return array<int, string>
     */
    protected function acceptedMimeTypes(Workspace $workspace): array
    {
        $types = [];

        foreach ($workspace->allowedAttachmentTypes() as $type) {
            foreach ($type->mimeTypes() as $mimeType) {
                $types[] = str_ends_with($mimeType, '/') ? $mimeType.'*' : $mimeType;
            }
        }

        return $types === [] ? ['application/x-nothing-at-all'] : $types;
    }

    /**
     * @return array<string, string>
     */
    protected function attachmentMessages(string $field = 'attachments', int $max = 10): array
    {
        return [
            $field.'.prohibited' => 'Bestanden delen staat uit in deze workspace.',
            $field.'.max' => 'Je kunt maximaal '.$max.' bestanden meesturen.',
            $field.'.*.max' => 'Dit bestand is groter dan in deze workspace is toegestaan.',
            $field.'.*.mimetypes' => 'Dit bestandstype is niet toegestaan in deze workspace.',
        ];
    }
}
