<?php

namespace App\Filament\Resources\Channels\Schemas;

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChannelForm
{
    /**
     * A moderator renames or retopics a channel; they do not move it to another
     * workspace, and they do not archive it here — that is its own action, so it
     * cannot happen as a side effect of fixing a typo.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Naam')
                            ->maxLength(255)
                            ->helperText('Leeg bij een DM.'),

                        TextInput::make('slug')
                            ->maxLength(255)
                            ->alphaDash()
                            ->helperText('Waarmee het channel met #naam wordt aangehaald.'),

                        Select::make('type')
                            ->label('Soort')
                            ->options(ChannelType::class)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->helperText('Van privé naar openbaar zetten maakt de geschiedenis leesbaar voor de hele workspace.'),

                        /**
                         * A radio rather than a select, because only Radio can
                         * carry the enum's descriptions — and "admins" on its own
                         * does not tell a moderator what changes for the channel.
                         */
                        Radio::make('posting_policy')
                            ->label('Wie mag hier posten')
                            ->options(ChannelPostingPolicy::class)
                            ->descriptions(collect(ChannelPostingPolicy::cases())
                                ->mapWithKeys(fn (ChannelPostingPolicy $policy): array => [
                                    $policy->value => $policy->description(),
                                ])
                                ->all())
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('topic')
                            ->label('Onderwerp')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
