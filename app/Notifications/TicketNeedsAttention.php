<?php

namespace App\Notifications;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Contracts\SendsPushover;
use App\Notifications\Messages\PushoverMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * "These tickets have been sitting."
 *
 * One notification per person for everything they are behind on, rather than
 * one per ticket — the same rule ChannelActivity follows, and for the same
 * reason: a phone that buzzes per ticket is a phone people switch off, and then
 * the one that mattered goes unread too.
 *
 * @phpstan-type Row array{number: int, title: string, reason: string, channel: string}
 */
class TicketNeedsAttention extends Notification implements SendsPushover, ShouldQueue
{
    use Queueable;

    /**
     * @param  Workspace  $workspace  Whose tickets these are, and so whose
     *                                mail settings the reminder goes out on.
     * @param  Collection<int, Ticket>  $tickets  Longest waiting first.
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly Collection $tickets,
    ) {
        // Ahead of the slow work: somebody is waiting for this one.
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            $notifiable->notify_via_mail ? 'mail' : null,
            $notifiable->wantsPushover() ? PushoverChannel::class : null,
        ]));
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            // On the workspace's own mailer when it has one — see
            // ChannelActivity for why it is resolved here and not passed in.
            ->mailer(app(ResolveWorkspaceMailer::class)->handle($this->workspace))
            ->subject($this->subject())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.tickets.intro'));

        foreach ($this->tickets as $ticket) {
            $mail->line(sprintf(
                '• #%d %s — %s',
                $ticket->number,
                $ticket->title,
                $this->reason($ticket),
            ));
        }

        $first = $this->tickets->first();

        return $mail->action(__('notifications.tickets.open'), route('chat.show', [
            $first->channel->workspace,
            $first->channel,
            'view' => 'tickets',
            'ticket' => $first->number,
        ]));
    }

    public function toPushover(User $notifiable): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->subject(),
            body: $this->tickets
                ->map(fn (Ticket $ticket): string => sprintf(
                    '#%d %s — %s',
                    $ticket->number,
                    $ticket->title,
                    $this->reason($ticket),
                ))
                ->join("\n"),
        );
    }

    private function subject(): string
    {
        return trans_choice('notifications.tickets.subject', $this->tickets->count());
    }

    /**
     * Why this ticket is in the list, in the reader's terms.
     *
     * The two reasons are different problems and want different answers: a late
     * ticket needs finishing, an unanswered one needs somebody to say anything
     * at all.
     */
    private function reason(Ticket $ticket): string
    {
        if ($ticket->due_at !== null && $ticket->due_at->isPast()) {
            return __('notifications.tickets.overdue');
        }

        return __('notifications.tickets.unanswered');
    }
}
