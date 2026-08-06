<?php

namespace App\Filament\Resources\Workspaces\RelationManagers;

use App\Actions\Chat\CreateChannel;
use App\Enums\ChannelType;
use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Models\Channel;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ChannelsRelationManager extends RelationManager
{
    use InteractsWithWorkspace;

    protected static string $relationship = 'channels';

    protected static ?string $title = 'Channels';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (Channel $record): ?string => $record->topic)
                    ->placeholder('— (DM)')
                    ->searchable(['name', 'slug', 'topic']),

                TextColumn::make('type')
                    ->label('Soort')
                    ->badge(),

                TextColumn::make('members_count')
                    ->label('Leden')
                    ->numeric(),

                TextColumn::make('messages_count')
                    ->label('Berichten')
                    ->numeric(),

                TextColumn::make('last_message_at')
                    ->label('Laatste bericht')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nog niets'),

                TextColumn::make('archived_at')
                    ->label('Gearchiveerd')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nee'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['members', 'messages']))
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Soort')
                    ->options(ChannelType::class),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Channel aanmaken')
                    ->modalHeading('Channel aanmaken')
                    ->schema([
                        TextInput::make('name')
                            ->label('Naam')
                            ->required()
                            ->maxLength(80)
                            /*
                             * Slugged before it is compared, which is what
                             * StoreChannelRequest does on the chat side and for
                             * the same reason: "Nieuwe Klanten" and "nieuwe
                             * klanten" are one address, and a plain unique rule
                             * on the typed name would let the second one
                             * through to the constraint.
                             */
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $taken = $this->workspace()
                                    ->channels()
                                    ->where('slug', Str::slug((string) $value))
                                    ->exists();

                                if ($taken) {
                                    $fail('Dit channel bestaat al in deze workspace.');
                                }
                            })
                            ->helperText('Wordt omgezet naar een adres: spaties en hoofdletters verdwijnen.'),

                        Select::make('type')
                            ->label('Soort')
                            /*
                             * A direct message is not a channel somebody makes
                             * — it is two people starting to talk, and the row
                             * is a side effect of that. Offering it here would
                             * be offering a conversation with nobody in it.
                             */
                            ->options(collect(ChannelType::cases())
                                ->reject(fn (ChannelType $type): bool => $type === ChannelType::Direct)
                                ->mapWithKeys(fn (ChannelType $type): array => [$type->value => $type->getLabel()]))
                            ->default(ChannelType::Public->value)
                            ->selectablePlaceholder(false)
                            ->required(),

                        TextInput::make('topic')
                            ->label('Onderwerp')
                            ->maxLength(255)
                            ->helperText('Waar dit channel over gaat. Mag leeg blijven.'),
                    ])
                    /*
                     * Through the same action the chat screen uses rather than
                     * the relationship, which would write a row and stop there
                     * — no slug, and nobody in the room. See CreateChannel.
                     *
                     * The creator is the workspace's owner and not the
                     * administrator pressing the button: that action puts the
                     * creator in the channel, and an administrator is usually
                     * not a member of the workspace at all. Putting them in
                     * would show a stranger in its member list.
                     */
                    ->using(fn (array $data): Channel => app(CreateChannel::class)->handle(
                        workspace: $this->workspace(),
                        creator: $this->workspace()->owner,
                        name: $data['name'],
                        type: ChannelType::from($data['type']),
                        topic: $data['topic'] ?: null,
                    )),
            ])
            ->recordActions([
                ToggleChannelArchivedAction::make(),
                DeleteAction::make()->label('Verwijderen'),
            ]);
    }
}
