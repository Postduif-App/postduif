<?php

namespace App\Console\Commands;

use App\Actions\Tickets\FindStaleTickets;
use App\Enums\Availability;
use App\Enums\WorkspaceRole;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNeedsAttention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('tickets:notify-stale')]
#[Description('Nudge whoever is responsible for tickets that have been left sitting')]
class NotifyStaleTickets extends Command
{
    public function handle(FindStaleTickets $findStaleTickets): int
    {
        $stale = $findStaleTickets->handle();

        if ($stale->isEmpty()) {
            $this->info('Niets blijft liggen.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($this->groupByRecipient($stale) as $userId => $tickets) {
            $user = User::find($userId);

            if ($user === null || ! $this->reachable($user)) {
                continue;
            }

            $user->notify(new TicketNeedsAttention($tickets));
            $notified++;
        }

        // Stamped whether or not anybody could be reached. A channel with no
        // reachable members would otherwise be re-examined every hour forever,
        // and the cooldown exists to bound the work as much as the noise.
        Ticket::whereKey($stale->pluck('id'))->update(['reminded_at' => now()]);

        $this->info($notified === 1
            ? '1 herinnering verstuurd.'
            : $notified.' herinneringen verstuurd.');

        return self::SUCCESS;
    }

    /**
     * Who hears about which tickets.
     *
     * The assignee when there is one — the work is theirs and nobody else needs
     * the reminder. Otherwise everyone in the channel who may act on tickets,
     * because an unassigned ticket that nobody answered is precisely the case
     * where there is no single person to tell.
     *
     * Guests are left out: a customer does not need to be told their own ticket
     * is being ignored.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, Collection<int, Ticket>>
     */
    private function groupByRecipient(Collection $tickets): Collection
    {
        $recipients = [];

        foreach ($tickets as $ticket) {
            foreach ($this->responsibleFor($ticket) as $userId) {
                $recipients[$userId][] = $ticket;
            }
        }

        return collect($recipients)->map(
            fn (array $rows): Collection => collect($rows)->sortBy('created_at')->values()
        );
    }

    /**
     * @return array<int, int>
     */
    private function responsibleFor(Ticket $ticket): array
    {
        if ($ticket->assigned_to !== null) {
            return [$ticket->assigned_to];
        }

        return $ticket->channel->members()
            ->whereNull('suspended_at')
            ->whereNotIn('users.id', $ticket->channel->workspace->members()
                ->wherePivot('role', WorkspaceRole::Guest->value)
                ->select('users.id'))
            ->pluck('users.id')
            ->all();
    }

    /**
     * Whether this member asked to hear anything at all.
     *
     * The same switches the absence summary honours: somebody who turned every
     * channel off did so for all of them, and do-not-disturb means now is not
     * the moment either way.
     */
    private function reachable(User $user): bool
    {
        return $user->suspended_at === null
            && $user->availability !== Availability::DoNotDisturb
            && ($user->notify_via_mail || $user->wantsPushover());
    }
}
