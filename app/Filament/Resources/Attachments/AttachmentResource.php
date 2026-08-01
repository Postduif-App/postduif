<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Every file shared in a conversation, across the platform.
 *
 * A list rather than a place to work: what it answers is "what is on the disk,
 * and where is the space going". Opening one is what the chat is for, so there
 * is no view page — only the list, its filters, and the running total under the
 * size column.
 */
class AttachmentResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static ?string $recordTitleAttribute = 'file_name';

    protected static ?string $modelLabel = 'bijlage';

    protected static ?string $pluralModelLabel = 'bijlagen';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return AttachmentsTable::configure($table);
    }

    /**
     * List only. Editing a file is not a thing — the bytes are what they are —
     * and removing one runs through the chat's own endpoint, which also cleans
     * up the message when the file was all it had.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAttachments::route('/'),
        ];
    }
}
