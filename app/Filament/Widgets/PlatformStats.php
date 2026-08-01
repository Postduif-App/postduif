<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    /**
     * Totals with a week's growth underneath them, because a total on its own
     * says nothing about whether the platform is still moving.
     *
     * Every figure is a count() against an indexed column rather than a loaded
     * collection, so the whole widget stays a handful of cheap queries however
     * large the platform gets.
     */
    protected function getStats(): array
    {
        $since = now()->subDays(7);

        return [
            Stat::make('Workspaces', Workspace::query()->count())
                ->description($this->newSince(Workspace::query()->where('created_at', '>=', $since)->count()))
                ->icon('heroicon-m-rectangle-stack'),

            Stat::make('Gebruikers', User::query()->count())
                ->description($this->newSince(User::query()->where('created_at', '>=', $since)->count()))
                ->icon('heroicon-m-users'),

            Stat::make('Channels', Channel::query()->count())
                ->description(Channel::query()->whereNotNull('archived_at')->count().' gearchiveerd')
                ->icon('heroicon-m-hashtag'),

            Stat::make('Berichten', Message::query()->count())
                ->description($this->newSince(Message::query()->where('created_at', '>=', $since)->count()))
                ->icon('heroicon-m-chat-bubble-left-right'),
        ];
    }

    private function newSince(int $count): string
    {
        return $count.' nieuw in 7 dagen';
    }
}
