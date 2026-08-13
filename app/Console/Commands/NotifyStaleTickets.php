<?php

namespace App\Console\Commands;

use App\Actions\Tickets\FindStaleTickets;
use App\Enums\Availability;
use App\Events\TicketWentStale;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
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

        foreach ($this->groupByRecipient($stale) as $key => $tickets) {
            [$userId, $workspaceId] = explode(':', (string) $key);

            $user = User::find((int) $userId);
            $workspace = Workspace::find((int) $workspaceId);

            if ($user === null || $workspace === null || ! $this->reachable($user)) {
                continue;
            }

            $user->notify(new TicketNeedsAttention($workspace, $tickets));
            $notified++;
        }

        /*
         * And the same news to whatever the workspace built for itself, one
         * event per ticket. Announced from here rather than from
         * FindStaleTickets because this is where the cooldown lives: that
         * action is a query and would answer the same question as often as it
         * were asked, which for a workflow would mean an hourly reminder about
         * a ticket nobody has touched.
         *
         * Before the stamp, so a listener reading the row still sees the
         * reminded_at it had when it was found.
         */
        foreach ($stale as $ticket) {
            TicketWentStale::dispatch($ticket->id, $this->whyStale($ticket));
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
     * Which of the two kinds of neglect this is.
     *
     * A broken promise or a silence — see FindStaleTickets, which looks for
     * both. Asked here rather than carried out of that action because it is one
     * comparison, and because a workflow wants it as a word it can put in a
     * condition rather than as two dates to work out for itself.
     */
    private function whyStale(Ticket $ticket): string
    {
        return $ticket->due_at !== null && $ticket->due_at->isPast() ? 'overdue' : 'unanswered';
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
     * Keyed by person *and* workspace rather than by person alone. One mail per
     * person was the rule and stays the rule within a workspace; across two of
     * them there is no single answer to "which mail settings does this go out
     * on", and a digest that mixed them would have to pick one workspace's
     * sender to talk about the other's tickets. Somebody who belongs to one
     * workspace — which is everybody today — notices no difference.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<string, Collection<int, Ticket>>
     */
    private function groupByRecipient(Collection $tickets): Collection
    {
        $recipients = [];

        foreach ($tickets as $ticket) {
            foreach ($this->responsibleFor($ticket) as $userId) {
                $recipients[$userId.':'.$ticket->workspace_id][] = $ticket;
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
            ->whereNotIn('users.id', $ticket->channel->workspace
                ->externalMembers()
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
