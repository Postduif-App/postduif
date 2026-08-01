<?php

namespace App\Filament\Resources\Workspaces;

use App\Filament\Resources\Workspaces\Pages\CreateWorkspace;
use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\Pages\EditWorkspaceFeatures;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\Pages\ViewWorkspace;
use App\Filament\Resources\Workspaces\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Workspaces\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Workspaces\Schemas\WorkspaceForm;
use App\Filament\Resources\Workspaces\Schemas\WorkspaceInfolist;
use App\Filament\Resources\Workspaces\Tables\WorkspacesTable;
use App\Models\Workspace;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'workspace';

    protected static ?string $pluralModelLabel = 'workspaces';

    protected static ?int $navigationSort = 1;

    /**
     * A report usually arrives as a slug from a URL, so search on that too.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function form(Schema $schema): Schema
    {
        return WorkspaceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkspaceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkspacesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            ChannelsRelationManager::class,
        ];
    }

    /**
     * The pages that belong to one workspace, as a menu beside it. Without
     * this, the features page would exist at a URL nobody can reach.
     *
     * @return array<int, NavigationItem>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewWorkspace::class,
            EditWorkspace::class,
            EditWorkspaceFeatures::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'create' => CreateWorkspace::route('/create'),
            'view' => ViewWorkspace::route('/{record}'),
            'edit' => EditWorkspace::route('/{record}/edit'),
            'features' => EditWorkspaceFeatures::route('/{record}/features'),
        ];
    }
}
