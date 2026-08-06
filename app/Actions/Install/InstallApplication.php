<?php

namespace App\Actions\Install;

use App\Actions\Users\GenerateHandle;
use App\Actions\Workspace\CreateWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Setting up a fresh platform: the first account and the first workspace.
 *
 * Both at once and in one transaction, because either on its own is a dead
 * end. An account with no workspace lands on the create-a-workspace form and
 * is nobody's moderator; a workspace with no admin is a room whose only key is
 * a shell prompt. The screen asks for both, so this writes both or neither.
 *
 * Not routed through CreateNewUser, though it makes the same kind of account.
 * That action refuses when registration is closed — rightly, it is the
 * sign-up door — and an installation that ships with REGISTRATION_OPEN=false
 * would then be one nobody could ever set up.
 */
class InstallApplication
{
    public function __construct(
        private readonly GenerateHandle $generateHandle,
        private readonly CreateWorkspace $createWorkspace,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, workspace: string}  $input
     */
    public function handle(array $input): User
    {
        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'username' => $this->generateHandle->handle($input['name'], $input['email']),
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            /*
             * forceFill for the same reason the panel and user:promote use it:
             * admin_at is not a fillable column anywhere, so the only way it is
             * ever written is deliberately and in sight.
             *
             * The address counts as verified. Nothing was mailed to prove it —
             * but this is the person who put the application on the server, and
             * a fresh install is exactly where mail is least likely to work.
             * Sending them a link they cannot receive would leave the platform
             * locked behind its own /email/verify screen.
             */
            $user->forceFill([
                'admin_at' => now(),
                'email_verified_at' => now(),
            ])->save();

            $this->createWorkspace->handle($user, $input['workspace']);

            return $user;
        });
    }
}
