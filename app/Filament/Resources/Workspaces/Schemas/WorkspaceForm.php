<?php

namespace App\Filament\Resources\Workspaces\Schemas;

use App\Enums\MemberPanelVisibility;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkspaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Naam')
                            ->required()
                            ->maxLength(255),

                        /**
                         * The slug is the workspace's route key, so editing it
                         * breaks every link people already have. Editable anyway,
                         * because a moderator sometimes has to rename something
                         * abusive, but never generated behind their back.
                         */
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Staat in de URL. Bestaande links breken als je dit wijzigt.'),

                        Select::make('owner_id')
                            ->label('Eigenaar')
                            ->relationship('owner', 'name')
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} (@{$record->username})")
                            ->searchable(['name', 'username', 'email'])
                            ->preload()
                            ->required(),

                        Select::make('member_panel')
                            ->label('Ledenlijst naast het gesprek')
                            ->options(MemberPanelVisibility::class)
                            ->default(MemberPanelVisibility::Off)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText('Wie de lijst met workspaceleden en hun status te zien krijgt. Gasten nooit.'),

                        /**
                         * Normalised on the way in, so the matcher never has to
                         * wonder whether "Sukkel " and "sukkel" are the same
                         * entry. Messages are filtered when they are shown, so
                         * a word added here applies to everything already said
                         * as well.
                         */
                        TagsInput::make('blocked_words')
                            ->label('Verboden woorden')
                            ->placeholder('Woord toevoegen')
                            ->nestedRecursiveRules(['string', 'max:255'])
                            ->dehydrateStateUsing(fn (?array $state): array => collect($state ?? [])
                                ->map(fn (string $word): string => mb_strtolower(trim($word)))
                                ->filter()
                                ->unique()
                                ->values()
                                ->all())
                            ->helperText('Deze woorden worden in berichten vervangen door sterretjes. Hoofdletters maken niet uit.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
