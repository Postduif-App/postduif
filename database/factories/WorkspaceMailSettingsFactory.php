<?php

namespace Database\Factories;

use App\Enums\MailTransport;
use App\Enums\SmtpEncryption;
use App\Models\Workspace;
use App\Models\WorkspaceMailSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMailSettings>
 */
class WorkspaceMailSettingsFactory extends Factory
{
    /**
     * A row that changes nothing, which is what a workspace starts with.
     *
     * The states below are what a test reaches for; the default is here so that
     * "has settings" and "sends differently" stay two separate facts a test can
     * ask for one at a time.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'transport' => MailTransport::Default,
        ];
    }

    public function smtp(): static
    {
        return $this->state([
            'transport' => MailTransport::Smtp,
            'smtp_host' => 'smtp.'.fake()->domainName(),
            'smtp_port' => 587,
            'smtp_encryption' => SmtpEncryption::StartTls,
            'smtp_username' => fake()->userName(),
            'smtp_password' => fake()->password(),
        ]);
    }

    public function postmark(): static
    {
        return $this->state([
            'transport' => MailTransport::Postmark,
            'postmark_token' => fake()->uuid(),
        ]);
    }

    public function lettermint(): static
    {
        return $this->state([
            'transport' => MailTransport::Lettermint,
            'lettermint_token' => fake()->uuid(),
        ]);
    }

    /** A sender of its own, on top of whichever transport was chosen. */
    public function from(string $address, ?string $name = null): static
    {
        return $this->state(['from_address' => $address, 'from_name' => $name]);
    }
}
