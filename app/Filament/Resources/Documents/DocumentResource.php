<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\Schemas\DocumentInfolist;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'document';

    protected static ?string $pluralModelLabel = 'documents';

    protected static ?int $navigationSort = 6;

    /**
     * By primary key here, unlike everywhere else.
     *
     * Document::getRouteKeyName() is the number, which is what people say out
     * loud and what the chat URL carries — and it is unique per workspace, not
     * across the platform. In the chat that is enough, because the workspace is
     * already in the path. This panel looks across every workspace at once, so
     * /admin/documents/1 would be as many documents as there are workspaces with
     * one, and Filament would hand back whichever came first.
     */
    public static function getRecordRouteKeyName(): ?string
    {
        return 'id';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    /**
     * Read and delete, and no editing — a narrower set than the tickets get,
     * which are read-only altogether.
     *
     * Editing is out for a stronger reason than it is there. A document is written
     * in an editor that does not exist in this panel, and its stored form is a
     * block tree; a textarea over that would not be a worse way to edit a
     * document, it would be a way to corrupt one.
     *
     * Deleting is in because it is the thing a platform moderator is actually
     * called for: something was put in a document that has to go, and the
     * channel it sits in may be private to people who will not act on it.
     * Soft, so it can be undone — see the restore action on the table.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }
}
