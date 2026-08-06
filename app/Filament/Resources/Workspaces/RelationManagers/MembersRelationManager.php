<?php

namespace App\Filament\Resources\Workspaces\RelationManagers;

use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    use InteractsWithWorkspace;

    protected static string $relationship = 'members';

    protected static ?string $title = 'Leden';

    /**
     * Everything here edits the pivot row, never the user: a moderator manages
     * who belongs to this workspace and in what role. Changing the user itself
     * is the UserResource's job.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label('Rol')
                    ->options(SystemRole::class)
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
                    ->label('Naam')
                    ->description(fn (User $record): string => '@'.$record->username)
                    ->searchable(['name', 'username']),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('Rol')
                    /*
                     * The row this member holds, by name. Not SystemRole::from()
                     * on the old string column: a workspace writes its own roles
                     * now, and "Leverancier" is not a case of that enum — it
                     * would throw rather than render.
                     */
                    ->state(fn (User $record): string => $this->workspace()
                        ->roleFor($record)->name ?? '—')
                    ->badge(),

                TextColumn::make('pivot.joined_at')
                    ->label('Lid sinds')
                    ->dateTime('d-m-Y H:i'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Rol')
                    /*
                     * The workspace's own roles rather than the built-in four,
                     * for the same reason as the column above — and filtering on
                     * the pointer, which is what the membership actually holds.
                     */
                    ->options(fn (): array => $this->workspace()
                        ->roles()
                        ->inOrder()
                        ->pluck('name', 'id')
                        ->all())
                    ->attribute('workspace_user.workspace_role_id'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Lid toevoegen')
                    ->recordSelectSearchColumns(['name', 'username', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label('Rol')
                            ->options(SystemRole::class)
                            ->default(SystemRole::Member)
                            ->selectablePlaceholder(false)
                            ->required(),
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
                 * Detaching the owner would leave the workspace pointing at
                 * someone who is no longer in it. Hand the workspace over first.
                 */
                DetachAction::make()
                    ->label('Verwijderen')
                    ->disabled(fn (User $record): bool => $this->isOwner($record))
                    ->tooltip(fn (User $record): ?string => $this->isOwner($record)
                        ? 'Wijs eerst een andere eigenaar aan.'
                        : null),
            ]);
    }

    private function isOwner(User $user): bool
    {
        return $this->workspace()->owner_id === $user->id;
    }
}
