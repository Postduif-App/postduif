<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Channels\Pages\ViewChannel;
use App\Filament\Resources\Channels\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Channels\RelationManagers\WebhooksRelationManager;
use App\Filament\Resources\Channels\Schemas\ChannelForm;
use App\Filament\Resources\Channels\Schemas\ChannelInfolist;
use App\Filament\Resources\Channels\Tables\ChannelsTable;
use App\Models\Channel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'channel';

    protected static ?string $pluralModelLabel = 'channels';

    protected static ?int $navigationSort = 3;

    /**
     * Opening a channel's record in the panel, which is not the same thing as
     * reading the channel.
     *
     * ChannelPolicy::view() guards the conversation itself and stays limited to
     * members — a moderator does not silently gain read access to private
     * channels in the chat UI. This page shows the channel's settings and
     * counts, so it answers to the panel instead.
     */
    public static function canView(Model $record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ChannelForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChannelInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChannelsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            WebhooksRelationManager::class,
        ];
    }

    /**
     * No create page: channels are made by the people using them.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            'view' => ViewChannel::route('/{record}'),
            'edit' => EditChannel::route('/{record}/edit'),
        ];
    }
}
