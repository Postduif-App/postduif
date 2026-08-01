<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class UserForm
{
    /**
     * Identity only. The password is not here on purpose — a moderator has no
     * business setting someone's password, and moderation rights are handed out
     * through their own confirmed action (see ToggleAdminAction).
     */
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
                         * Handles are generated with dots ("fenna.de.vries"), so
                         * alphaDash would reject the ones already out there.
                         */
                        TextInput::make('username')
                            ->label('Gebruikersnaam')
                            ->prefix('@')
                            ->required()
                            ->maxLength(255)
                            ->rules(['regex:/^[a-z0-9._-]+$/i', Rule::notIn(User::RESERVED_HANDLES)])
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'regex' => 'Gebruik alleen letters, cijfers, punten, streepjes en underscores.',
                                'not_in' => 'Dit handle spreekt een hele groep aan en is daarom niet beschikbaar.',
                            ])
                            ->helperText('Waarmee anderen deze persoon mentionen.'),

                        TextInput::make('email')
                            ->label('E-mailadres')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
