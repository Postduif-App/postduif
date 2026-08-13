<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Role;
use App\Models\Workspace;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The other side of the membership: which workspaces this person belongs to.
 *
 * The workspace page has had a member list for a while, which answers "who is
 * in here". This answers the question a moderator actually arrives with —
 * somebody asks to be let into a second workspace, and the moderator is
 * looking at that person, not at the workspace. Without it the only way in was
 * to go and find every workspace in turn.
 *
 * Nothing here edits the user. It writes the row between the two, exactly as
 * MembersRelationManager does from the other end.
 */
class WorkspacesRelationManager extends RelationManager
{
    protected static string $relationship = 'workspaces';

    protected static ?string $title = 'Workspaces';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('workspace_role_id')
                    ->label('Rol')
                    ->options(fn (?Workspace $record): array => $record === null
                        ? []
                        : $this->roleOptions($record))
                    ->selectablePlaceholder(false)
                    ->required(),

                TextInput::make('display_name')
                    ->label('Weergavenaam in deze workspace')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Workspace')
                    ->description(fn (Workspace $record): string => '/'.$record->slug)
                    ->searchable(['name', 'slug']),

                TextColumn::make('role')
                    ->label('Rol')
                    /*
                     * Off the membership this row was loaded through rather
                     * than a second query per row: the pivot carries the
                     * pointer, and the name lives on the role it points at.
                     */
                    ->state(fn (Workspace $record): string => $record->membership
                        ->workspaceRole?->name ?? '—')
                    ->badge(),

                TextColumn::make('membership.joined_at')
                    ->label('Lid sinds')
                    ->dateTime('d-m-Y H:i'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Aan workspace koppelen')
                    ->recordSelectSearchColumns(['name', 'slug'])
                    /*
                     * Named columns rather than workspaces.*, because Filament
                     * asks for the options with a distinct. Postgres cannot
                     * compare two json values for equality, so a distinct over
                     * every column dies on blocked_words — and the three
                     * columns below are all the select shows anyway.
                     */
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->select(['workspaces.id', 'workspaces.name', 'workspaces.slug']))
                    ->schema(fn (AttachAction $action): array => [
                        /*
                         * Live, because the roles underneath belong to the
                         * workspace picked here. A workspace writes its own,
                         * so the list cannot be built before one is chosen.
                         */
                        $action->getRecordSelect()->live(),

                        Select::make('workspace_role_id')
                            ->label('Rol')
                            ->options(fn (Get $get): array => $this->roleOptionsFor($get('recordId')))
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText('Kies eerst een workspace.'),
                    ])
                    ->mutateDataUsing(function (array $data): array {
                        $data['joined_at'] = now();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Rol wijzigen')
                    ->modalHeading('Rol wijzigen'),

                /**
                 * The same guard as the member list on the workspace page:
                 * detaching an owner leaves a workspace pointing at somebody
                 * who is no longer in it.
                 */
                DetachAction::make()
                    ->label('Ontkoppelen')
                    ->disabled(fn (Workspace $record): bool => $record->owner_id === $this->getOwnerRecord()->getKey())
                    ->tooltip(fn (Workspace $record): ?string => $record->owner_id === $this->getOwnerRecord()->getKey()
                        ? 'Wijs eerst een andere eigenaar aan.'
                        : null),
            ]);
    }

    /**
     * The roles a workspace wrote for itself.
     *
     * @return array<int, string>
     */
    private function roleOptions(Workspace $workspace): array
    {
        return $workspace->roles()->pluck('name', 'id')->all();
    }

    /**
     * The same list, from whatever the record select currently holds — which
     * is nothing at all until somebody picks a workspace.
     *
     * @return array<int, string>
     */
    private function roleOptionsFor(mixed $workspaceId): array
    {
        if (blank($workspaceId)) {
            return [];
        }

        return Role::query()
            ->where('workspace_id', $workspaceId)
            ->inOrder()
            ->pluck('name', 'id')
            ->all();
    }
}
