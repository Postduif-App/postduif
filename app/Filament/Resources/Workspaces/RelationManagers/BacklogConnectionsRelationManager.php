<?php

namespace App\Filament\Resources\Workspaces\RelationManagers;

use App\Models\BacklogConnection;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;

/**
 * A workspace's arrangements with Backlog.
 *
 * Unlike WebhooksRelationManager, neither secret on this record is ours to
 * mint. client_secret is Backlog's Passport client secret, printed once by
 * `passport:client:postduif` on the Backlog side; webhook_secret is what
 * Backlog's own WebhookEndpoint shows once when an admin creates it there
 * (WebhookController::store, `$hidden`, never fillable — Backlog will not
 * accept a secret we invent). Generating either of them here would produce a
 * value the far end never agreed to sign or authenticate with, so both are
 * plain fields an admin pastes in and can update, not a reveal-once modal we
 * generate. Only the callback URL is genuinely ours to show.
 */
class BacklogConnectionsRelationManager extends RelationManager
{
    use InteractsWithWorkspace;

    protected static string $relationship = 'backlogConnections';

    protected static ?string $title = 'Backlog';

    /**
     * The callback URL, held only long enough to show it once after a
     * connection is created — it needs the row's id, so it does not exist
     * before then. Unlike the two secrets, this one is genuinely ours to
     * mint and reveal; there is nothing wrong with looking it up again later,
     * this is just a convenience so the admin does not have to.
     */
    public ?string $freshWebhookUrl = null;

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('backlog_url')
            ->columns([
                TextColumn::make('backlog_url')
                    ->label('Backlog URL')
                    ->searchable(),

                TextColumn::make('channel.name')
                    ->label('Channel')
                    ->placeholder('— (DM)'),

                TextColumn::make('events')
                    ->label('Events')
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(2),

                IconColumn::make('is_active')
                    ->label('Actief')
                    ->boolean(),

                TextColumn::make('consecutive_failures')
                    ->label('Mislukte pogingen op rij')
                    ->numeric()
                    ->color(fn (BacklogConnection $record): string => $record->isHealthy() ? 'gray' : 'danger'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('channel'))
            ->defaultSort('id', 'desc')
            ->headerActions([
                $this->createAction(),
                $this->showWebhookUrlAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (): array => $this->fields(editing: true))
                    ->using(function (BacklogConnection $record, array $data): BacklogConnection {
                        /*
                         * Neither secret is in the model's Fillable list — on
                         * purpose, the same reason createAction() never went
                         * through mass assignment for them either. The plain
                         * update() below would silently drop whichever of
                         * these two showed up in $data: Eloquent discards a
                         * non-fillable key rather than refusing it, so a typed
                         * secret would look saved and never actually be.
                         */
                        $secrets = array_intersect_key($data, array_flip(['client_secret', 'webhook_secret']));
                        $record->update(array_diff_key($data, $secrets));

                        if ($secrets !== []) {
                            $record->forceFill($secrets)->save();
                        }

                        return $record;
                    }),
                $this->testConnectionAction(),
                $this->toggleActiveAction(),
                DeleteAction::make()->label('Verwijderen'),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function fields(bool $editing): array
    {
        return [
            TextInput::make('backlog_url')
                ->label('Backlog URL')
                ->helperText('Het adres van de Backlog-installatie, bijvoorbeeld https://backlog.example.com.')
                ->url()
                ->required()
                ->maxLength(255),

            TextInput::make('backlog_workspace_id')
                ->label('Backlog workspace-id')
                ->helperText('Alleen nodig om nieuwe issues te kunnen aanmaken vanuit een workflow-actie. Te vinden via GET /api/v1/me op Backlog, onder de toegankelijke workspaces. Leeg laten als deze connectie alleen bestaande issues synchroniseert.')
                ->numeric()
                ->integer()
                ->required(false),

            TextInput::make('client_id')
                ->label('Client ID')
                ->helperText('Het OAuth client-credentials ID dat Backlog voor deze workspace heeft uitgegeven (php artisan passport:client:postduif).')
                ->required()
                ->maxLength(255),

            TextInput::make('client_secret')
                ->label('Client secret')
                ->helperText($editing
                    ? 'Leeg laten om de huidige secret te behouden. Backlog kan geen bestaande client-secret opnieuw tonen — alleen invullen als de client op Backlog opnieuw is geprovisioneerd.'
                    : 'Precies wat passport:client:postduif op Backlog heeft geprint. Postduif kan dit niet zelf verzinnen: Backlog beslist wat het accepteert.')
                ->password()
                ->revealable()
                ->required(! $editing)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->maxLength(255),

            TextInput::make('webhook_secret')
                ->label('Webhook secret')
                ->helperText($editing
                    ? 'Leeg laten om de huidige secret te behouden. Alleen invullen als de webhook-endpoint op Backlog opnieuw is aangemaakt of het secret daar geroteerd is.'
                    : 'Precies de secret die Backlog toont bij het aanmaken van de webhook-endpoint. Postduif kan dit niet zelf verzinnen: Backlog signeert ermee, dus alleen wat Backlog liet zien werkt hier.')
                ->password()
                ->revealable()
                ->required(! $editing)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->maxLength(255),

            Select::make('channel_id')
                ->label('Channel')
                ->helperText('Waar gesynchroniseerde tickets terechtkomen.')
                ->options(fn (): array => $this->workspace()->channels()
                    ->get()
                    ->filter(fn (Channel $channel): bool => $channel->hasTickets())
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),

            CheckboxList::make('events')
                ->label('Events')
                ->helperText('Waar deze connectie webhooks voor wil ontvangen.')
                ->options(array_combine(BacklogConnection::EVENTS, BacklogConnection::EVENTS))
                ->default(BacklogConnection::EVENTS)
                ->required(),
        ];
    }

    public function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Backlog-connectie toevoegen')
            ->modalHeading('Nieuwe Backlog-connectie')
            ->schema(fn (): array => $this->fields(editing: false))
            ->using(function (array $data): BacklogConnection {
                $connection = new BacklogConnection([
                    'workspace_id' => $this->workspace()->id,
                    'channel_id' => $data['channel_id'],
                    'backlog_url' => $data['backlog_url'],
                    'backlog_workspace_id' => $data['backlog_workspace_id'] ?: null,
                    'client_id' => $data['client_id'],
                    'events' => array_values(array_intersect(BacklogConnection::EVENTS, $data['events'])),
                ]);

                // Both required on create (see fields()), and both exactly
                // what the admin pasted — neither is ours to generate. See
                // the class docblock for why.
                $connection->forceFill([
                    'client_secret' => $data['client_secret'],
                    'webhook_secret' => $data['webhook_secret'],
                ]);

                $connection->save();

                $this->freshWebhookUrl = route('webhooks.backlog.store', $connection);

                return $connection;
            })
            /*
             * mountAction() from inside the callback would be lost while the
             * create modal is still closing — see WebhooksRelationManager's
             * createAction for the same trade.
             */
            ->after(fn () => $this->js("\$wire.mountAction('showWebhookUrl')"));
    }

    /**
     * A connection to Backlog's own `/api/v1/me`, using the credentials
     * already on the row — nothing typed on this screen goes anywhere until
     * it has been saved.
     */
    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connectie')
            ->icon('heroicon-m-signal')
            ->action(function (BacklogConnection $record): void {
                $token = $record->accessToken();

                if ($token === null) {
                    Notification::make()
                        ->title('Kon niet authenticeren bij Backlog')
                        ->body('De client-credentials zijn geweigerd of de URL is onbereikbaar.')
                        ->danger()
                        ->send();

                    return;
                }

                $response = Http::withToken($token)
                    ->timeout(5)
                    ->connectTimeout(3)
                    ->get(rtrim($record->backlog_url, '/').'/api/v1/me');

                if ($response->successful()) {
                    Notification::make()
                        ->title('Verbinding gelukt')
                        ->body($response->json('name') ?? 'Backlog antwoordde.')
                        ->success()
                        ->send();

                    $record->recordSuccess();

                    return;
                }

                Notification::make()
                    ->title('Verbinding mislukt')
                    ->body("Backlog antwoordde met status {$response->status()}.")
                    ->danger()
                    ->send();

                $record->recordFailure();
            });
    }

    /**
     * The callback URL right after a connection is created.
     *
     * No secret in this one — see the class docblock for why there is
     * nothing here for Postduif to generate or rotate any more. An admin who
     * needs the URL again later reads it straight off the table instead;
     * this is only a courtesy for the moment right after creating a row,
     * when the modal that just closed is the natural place to hand it over.
     */
    public function showWebhookUrlAction(): Action
    {
        return Action::make('showWebhookUrl')
            ->label('Webhook-URL')
            ->modalHeading('De webhook-URL voor Backlog')
            ->modalContent(fn () => view('filament.backlog-connection-secret', [
                'label' => 'Webhook-URL',
                'value' => $this->freshWebhookUrl,
                'help' => 'Voer dit adres in bij het aanmaken van de webhook-endpoint op Backlog.',
            ]))
            ->modalSubmitActionLabel('Sluiten')
            ->modalCancelAction(false)
            ->action(fn () => $this->freshWebhookUrl = null)
            ->visible(fn (): bool => filled($this->freshWebhookUrl));
    }

    /**
     * Switch a connection on or off without touching either secret.
     *
     * Separate from EditAction the way ContractWebhookController's toggle is
     * separate from its update: flipping whether something listens should not
     * require re-typing what it listens for.
     */
    public function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (BacklogConnection $record): string => $record->is_active ? 'Uitzetten' : 'Aanzetten')
            ->icon(fn (BacklogConnection $record): string => $record->is_active ? 'heroicon-m-pause' : 'heroicon-m-play')
            ->action(function (BacklogConnection $record): void {
                $record->forceFill([
                    'is_active' => ! $record->is_active,
                    'consecutive_failures' => 0,
                ])->save();
            });
    }
}
