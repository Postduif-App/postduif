<?php

namespace App\Filament\Resources\Channels\RelationManagers;

use App\Models\Channel;
use App\Models\Webhook;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A channel's incoming webhooks, in the admin panel.
 *
 * The same things the channel's own settings offer — create, look the URL up,
 * replace it, revoke — so an admin never has to go and find somebody with
 * access to the channel to set an integration up.
 *
 * Nothing here exposes token_hash, and the token itself only ever leaves
 * through the URL action below. The hash is not the token, but it is the only
 * thing between a copied row and posting rights.
 */
class WebhooksRelationManager extends RelationManager
{
    protected static string $relationship = 'webhooks';

    protected static ?string $title = 'Webhooks';

    /**
     * The URL the token modal is about to show.
     *
     * Held for one Livewire round trip rather than kept around: it carries a
     * live credential, and there is no reason for it to ride along in the
     * payload of every later request on this page. It can always be looked up
     * again — see Webhook::url().
     */
    public ?string $freshTokenUrl = null;

    /**
     * Filament makes relation managers read-only on a resource's view page, so
     * the built-in create action would be denied there. That default is right
     * for records you are only inspecting; a webhook is something you come to
     * this page to set up.
     *
     * The custom revoke action below is unaffected either way — read-only only
     * governs Filament's own create, edit, delete and attach actions.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Filament asks a WebhookPolicy for this, and an ability nothing defines is
     * denied. Rather than add a policy that exists solely for the panel, mirror
     * what already guards the channel record itself: ChannelResource::canView
     * is admin-only, and everything under it inherits that.
     */
    protected function canCreate(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(),

                TextColumn::make('bot_name')
                    ->label('Postte als')
                    ->description('De naam die bij de berichten staat')
                    ->searchable(),

                TextColumn::make('creator.name')
                    ->label('Aangemaakt door')
                    ->placeholder('— (verwijderd account)'),

                TextColumn::make('last_used_at')
                    ->label('Laatst gebruikt')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nooit')
                    ->sortable(),

                TextColumn::make('revoked_at')
                    ->label('Ingetrokken')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('Nee'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->defaultSort('last_used_at', 'desc')
            ->headerActions([
                $this->createAction(),
                $this->showTokenAction(),
            ])
            ->recordActions([
                $this->showUrlAction(),
                $this->regenerateAction(),
                $this->revokeAction(),
            ]);
    }

    /**
     * Public, and named for the action it builds: Filament resolves an action
     * by looking for a "{name}Action" method on the component, so a private one
     * of the same name shadows it and the action becomes uncallable.
     *
     * The token gets a second modal rather than this one's success message,
     * because it needs room to be read and copied.
     */
    public function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Webhook toevoegen')
            ->modalHeading('Nieuwe webhook')
            ->schema([
                TextInput::make('name')
                    ->label('Naam')
                    ->helperText('Waar deze webhook voor is, bijvoorbeeld "CI".')
                    ->required()
                    ->maxLength(80),

                TextInput::make('bot_name')
                    ->label('Postte als')
                    ->helperText('De naam bij de berichten. Er staat altijd BOT naast.')
                    ->required()
                    ->maxLength(80),
            ])
            ->using(function (array $data): Webhook {
                /** @var Channel $channel */
                $channel = $this->getOwnerRecord();

                $webhook = new Webhook([
                    'workspace_id' => $channel->workspace_id,
                    'channel_id' => $channel->id,
                    'name' => $data['name'],
                    'bot_name' => $data['bot_name'],
                    'created_by' => auth()->id(),
                ]);

                $this->freshTokenUrl = route(
                    'webhooks.messages.store',
                    $webhook->regenerateToken(),
                );

                $webhook->save();

                return $webhook;
            })
            /**
             * mountAction() from inside the callback would be lost while the
             * create modal is still closing, so queue it on the client instead.
             */
            ->after(fn () => $this->js("\$wire.mountAction('showToken')"));
    }

    /**
     * Look up the URL of an existing webhook.
     *
     * Behind an action rather than in a column: the table is read at a glance
     * by whoever is moderating, and a list of live posting URLs is not
     * something to put on screen unasked.
     */
    public function showUrlAction(): Action
    {
        return Action::make('showUrl')
            ->label('Toon de URL')
            ->icon('heroicon-m-link')
            ->modalHeading('De URL van deze webhook')
            ->modalContent(fn (Webhook $record) => view('filament.webhook-token', [
                'url' => $record->url(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }

    /**
     * Hand out a new token. One-way in the same sense revoking is: the URL that
     * was in use stops working, so it asks first.
     */
    public function regenerateAction(): Action
    {
        return Action::make('regenerate')
            ->label('Vervang de URL')
            ->icon('heroicon-m-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('De huidige URL werkt hierna niet meer. Wat er nu mee post, moet de nieuwe URL krijgen.')
            ->hidden(fn (Webhook $record): bool => $record->isRevoked())
            ->action(function (Webhook $record) {
                $this->freshTokenUrl = route(
                    'webhooks.messages.store',
                    $record->regenerateToken(),
                );

                $record->save();

                $this->js("\$wire.mountAction('showToken')");
            });
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->label('Toon de URL')
            ->modalHeading('De nieuwe URL')
            ->modalContent(fn () => view('filament.webhook-token', [
                'url' => $this->freshTokenUrl,
            ]))
            ->modalSubmitActionLabel('Sluiten')
            ->modalCancelAction(false)
            // Closing drops the token out of the component's state, so it stops
            // riding along in the Livewire payload of every later request.
            ->action(fn () => $this->freshTokenUrl = null)
            // Only exists in the moment between creating a webhook and closing
            // this modal; a hidden action could not be mounted at all.
            ->visible(fn (): bool => filled($this->freshTokenUrl));
    }

    /**
     * Revoking is one-way on purpose. Re-enabling would mean handing the old
     * token back out, and the reason to revoke is usually that the old token is
     * somewhere it should not be — so a new webhook is the only honest answer.
     */
    public function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Intrekken')
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Het token werkt hierna niet meer. Dit is niet terug te draaien; maak zo nodig een nieuwe webhook aan.')
            ->hidden(fn (Webhook $record): bool => $record->isRevoked())
            ->action(fn (Webhook $record) => $record->forceFill(['revoked_at' => now()])->save());
    }
}
