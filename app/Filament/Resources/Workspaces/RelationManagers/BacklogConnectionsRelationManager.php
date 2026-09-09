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
use Illuminate\Support\Str;

/**
 * A workspace's arrangements with Backlog.
 *
 * The same shape as WebhooksRelationManager: create, look a secret up once,
 * rotate it, switch off, delete. Two secrets live on this record rather than
 * one — client_secret authenticates calls this server makes outward,
 * webhook_secret verifies what Backlog calls back with — so each gets its own
 * reveal-once modal rather than sharing WebhooksRelationManager's.
 */
class BacklogConnectionsRelationManager extends RelationManager
{
    use InteractsWithWorkspace;

    protected static string $relationship = 'backlogConnections';

    protected static ?string $title = 'Backlog';

    /**
     * Held only long enough to show it once, the same trade every secret on
     * this page makes — see Webhook::regenerateToken.
     */
    public ?string $freshClientSecret = null;

    public ?string $freshWebhookSecret = null;

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
                $this->showClientSecretAction(),
                $this->showWebhookSecretAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (): array => $this->fields(editing: true)),
                $this->testConnectionAction(),
                $this->rotateClientSecretAction(),
                $this->rotateWebhookSecretAction(),
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

            TextInput::make('client_id')
                ->label('Client ID')
                ->helperText('Het OAuth client-credentials ID dat Backlog voor deze workspace heeft uitgegeven.')
                ->required()
                ->maxLength(255),

            ...($editing ? [] : [
                TextInput::make('client_secret')
                    ->label('Client secret')
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(255),
            ]),

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
                    'client_id' => $data['client_id'],
                    'events' => array_values(array_intersect(BacklogConnection::EVENTS, $data['events'])),
                ]);

                $connection->forceFill(['client_secret' => $data['client_secret']]);

                $this->freshWebhookSecret = 'whs_'.Str::random(48);
                $connection->forceFill(['webhook_secret' => $this->freshWebhookSecret]);

                $connection->save();

                $this->freshClientSecret = $data['client_secret'];
                $this->freshWebhookUrl = route('webhooks.backlog.store', $connection);

                return $connection;
            })
            /*
             * mountAction() from inside the callback would be lost while the
             * create modal is still closing — see WebhooksRelationManager's
             * createAction for the same trade.
             */
            ->after(fn () => $this->js("\$wire.mountAction('showWebhookSecret')"));
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

    public function rotateClientSecretAction(): Action
    {
        return Action::make('rotateClientSecret')
            ->label('Client secret vervangen')
            ->icon('heroicon-m-key')
            ->requiresConfirmation()
            ->modalDescription('De huidige client secret werkt hierna niet meer bij Backlog.')
            ->action(function (BacklogConnection $record): void {
                $secret = 'bls_'.Str::random(48);

                $record->forceFill(['client_secret' => $secret])->save();

                $this->freshClientSecret = $secret;

                $this->js("\$wire.mountAction('showClientSecret')");
            });
    }

    public function rotateWebhookSecretAction(): Action
    {
        return Action::make('rotateWebhookSecret')
            ->label('Webhook secret vervangen')
            ->icon('heroicon-m-key')
            ->requiresConfirmation()
            ->modalDescription('Backlog moet met de nieuwe secret opnieuw worden geconfigureerd, anders worden zijn webhooks geweigerd.')
            ->action(function (BacklogConnection $record): void {
                $secret = 'whs_'.Str::random(48);

                $record->forceFill(['webhook_secret' => $secret])->save();

                $this->freshWebhookSecret = $secret;
                $this->freshWebhookUrl = route('webhooks.backlog.store', $record);

                $this->js("\$wire.mountAction('showWebhookSecret')");
            });
    }

    public function showClientSecretAction(): Action
    {
        return Action::make('showClientSecret')
            ->label('Client secret')
            ->modalHeading('De nieuwe client secret')
            ->modalContent(fn () => view('filament.backlog-connection-secret', [
                'label' => 'Client secret',
                'value' => $this->freshClientSecret,
                'help' => 'Bewaar deze; hij is hierna niet meer op te vragen.',
            ]))
            ->modalSubmitActionLabel('Sluiten')
            ->modalCancelAction(false)
            ->action(fn () => $this->freshClientSecret = null)
            ->visible(fn (): bool => filled($this->freshClientSecret));
    }

    public function showWebhookSecretAction(): Action
    {
        return Action::make('showWebhookSecret')
            ->label('Webhook secret')
            ->modalHeading('De webhook-URL en het bijbehorende secret')
            ->modalContent(fn () => view('filament.backlog-connection-secret', [
                'label' => 'Webhook secret',
                'value' => $this->freshWebhookSecret,
                'url' => $this->freshWebhookUrl,
                'help' => 'Voer beide in bij Backlog; het secret is hierna niet meer op te vragen.',
            ]))
            ->modalSubmitActionLabel('Sluiten')
            ->modalCancelAction(false)
            ->action(function (): void {
                $this->freshWebhookSecret = null;
                $this->freshWebhookUrl = null;
            })
            ->visible(fn (): bool => filled($this->freshWebhookSecret));
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
