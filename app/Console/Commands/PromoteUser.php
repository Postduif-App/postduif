<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\search;

/**
 * Handing somebody the keys to every workspace from the command line.
 *
 * The panel already has ToggleAdminAction for this, but that action needs a
 * moderator to run it — which leaves a fresh installation with nobody who can
 * get in. This is the way in from outside: whoever can reach the server can
 * appoint the first moderator, and after that the panel takes over.
 *
 * admin_at stays out of the user's fillable columns here too, for the same
 * reason it does in the panel: the column is written with an explicit
 * forceFill and nowhere else.
 */
#[Signature('user:promote {email? : Het e-mailadres van de gebruiker} {--revoke : Rechten juist intrekken}')]
#[Description('Maak een gebruiker moderator van het platform')]
class PromoteUser extends Command
{
    public function handle(): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->components->error('Geen gebruiker gevonden.');

            return self::FAILURE;
        }

        return $this->option('revoke')
            ? $this->revoke($user)
            : $this->promote($user);
    }

    private function promote(User $user): int
    {
        if ($user->isAdmin()) {
            $this->components->warn($user->email.' is al moderator.');

            return self::SUCCESS;
        }

        $this->components->warn($user->name.' ('.$user->email.') krijgt toegang tot het adminpanel en tot elke workspace op het platform.');

        if (! $this->confirm('Doorgaan?', true)) {
            $this->components->info('Niets gewijzigd.');

            return self::SUCCESS;
        }

        $user->forceFill(['admin_at' => now()])->save();

        $this->components->info($user->email.' is nu moderator.');

        // Worth saying out loud: canAccessPanel() refuses a suspended account,
        // so the promotion looks like it did nothing until the suspension is
        // lifted. Better to hear that here than to go hunting for it later.
        if ($user->isSuspended()) {
            $this->components->warn('Let op: dit account is geschorst en komt pas in het panel als de schorsing is opgeheven.');
        }

        return self::SUCCESS;
    }

    private function revoke(User $user): int
    {
        if (! $user->isAdmin()) {
            $this->components->warn($user->email.' is geen moderator.');

            return self::SUCCESS;
        }

        $this->components->warn($user->name.' ('.$user->email.') verliest toegang tot het adminpanel.');

        if (! $this->confirm('Doorgaan?', true)) {
            $this->components->info('Niets gewijzigd.');

            return self::SUCCESS;
        }

        $user->forceFill(['admin_at' => null])->save();

        $this->components->info('Rechten van '.$user->email.' ingetrokken.');

        return self::SUCCESS;
    }

    /**
     * The e-mail address as an argument when it is known, a search when it is
     * not. Typing a full address from memory is exactly the kind of thing that
     * goes wrong silently, and picking the wrong person here is expensive.
     */
    private function resolveUser(): ?User
    {
        $email = $this->argument('email');

        if (filled($email)) {
            return User::where('email', $email)->first();
        }

        $id = search(
            label: 'Welke gebruiker?',
            options: fn (string $term): array => $this->matching($term),
            placeholder: 'Zoek op naam of e-mailadres',
            scroll: 10,
        );

        return User::find($id);
    }

    /**
     * The options behind the search box. Bounded on purpose: the closure runs
     * again on every keystroke, so an unbounded query would grow into a table
     * scan per letter on a platform of any size.
     *
     * @return array<int, string>
     */
    private function matching(string $term): array
    {
        return User::query()
            ->when(filled($term), fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')))
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => $user->name.' — '.$user->email.($user->isAdmin() ? ' (moderator)' : ''),
            ])
            ->all();
    }
}
