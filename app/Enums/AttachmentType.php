<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kinds of file a workspace may allow in its conversations.
 *
 * Groups rather than a list of mime types, because this is a setting somebody
 * reads and ticks: "afbeeldingen" is a decision, "image/avif" is trivia. The
 * mime types behind each group are this enum's business, and adding one to a
 * group later is a change nobody has to go and tick again.
 *
 * Note what is deliberately absent: there is no group that lets in HTML, SVG or
 * anything else that carries script. Those are turned away at the door rather
 * than made available behind a setting somebody might tick without knowing what
 * it opens — see MessageAttachmentController, which also refuses to render them.
 */
enum AttachmentType: string implements HasLabel
{
    case Images = 'images';
    case Video = 'video';
    case Audio = 'audio';
    case Documents = 'documents';
    case Archives = 'archives';

    /**
     * What a workspace allows unless somebody says otherwise.
     *
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        return [
            self::Images->value,
            self::Video->value,
            // Audio is on by default because the composer can record one: a
            // microphone button that is missing until somebody finds a settings
            // screen is a feature nobody discovers.
            self::Audio->value,
            self::Documents->value,
        ];
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Images => __('enums.attachment-type.label.Images'),
            self::Video => __('enums.attachment-type.label.Video'),
            self::Audio => __('enums.attachment-type.label.Audio'),
            self::Documents => __('enums.attachment-type.label.Documents'),
            self::Archives => __('enums.attachment-type.label.Archives'),
        };
    }

    /** The examples somebody needs to recognise what they are ticking. */
    public function hint(): string
    {
        return match ($this) {
            self::Images => __('enums.attachment-type.hint.Images'),
            self::Video => __('enums.attachment-type.hint.Video'),
            self::Audio => __('enums.attachment-type.hint.Audio'),
            self::Documents => __('enums.attachment-type.hint.Documents'),
            self::Archives => __('enums.attachment-type.hint.Archives'),
        };
    }

    /**
     * The mime types this group stands for.
     *
     * A trailing slash means the whole family: "image/" accepts anything the
     * browser calls an image. Everything else is spelled out, because the
     * document and archive families have no shared prefix to lean on.
     *
     * @return array<int, string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            // Not image/, which would include svg+xml — a script in a costume.
            self::Images => [
                'image/png',
                'image/jpeg',
                'image/gif',
                'image/webp',
                'image/avif',
                'image/heic',
                'image/bmp',
            ],
            self::Video => ['video/'],
            self::Audio => ['audio/'],
            self::Documents => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.text',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/plain',
                'text/csv',
            ],
            self::Archives => [
                'application/zip',
                'application/x-7z-compressed',
                'application/x-tar',
                'application/gzip',
                'application/x-rar-compressed',
            ],
        };
    }

    /** Whether a file of this mime type belongs to this group. */
    public function accepts(string $mimeType): bool
    {
        $mimeType = mb_strtolower(trim($mimeType));

        foreach ($this->mimeTypes() as $allowed) {
            $matches = str_ends_with($allowed, '/')
                ? str_starts_with($mimeType, $allowed)
                : $mimeType === $allowed;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
