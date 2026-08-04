<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\SentSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<SentSecret>
 */
class SentSecretFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'channel_id' => Channel::factory(),
            'created_by' => User::factory(),
            'recipient_id' => User::factory(),
            'label' => 'Wachtwoord staging-database',
            /*
             * Not real ciphertext, and it does not need to be: nothing on the
             * server ever decrypts this. What the tests care about is whether
             * the exact bytes come back once and are gone afterwards, and a
             * recognisable string says that far more clearly in a failure
             * message than a base64 blob would.
             */
            'ciphertext' => 'versleuteld-in-de-browser',
            'iv' => 'AAAAAAAAAAAAAAAA',
            'password_hash' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /** Already picked up, and therefore empty. */
    public function revealed(): static
    {
        return $this->state([
            'ciphertext' => '',
            'iv' => '',
            'revealed_at' => now(),
        ]);
    }

    /** Its moment has passed. */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    /** With a second gate on it. */
    public function withPassword(string $password = 'sleutelwoord'): static
    {
        return $this->state(['password_hash' => Hash::make($password)]);
    }
}
