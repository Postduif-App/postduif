<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Chrome by default, because it is what most of these rows will be.
            // The shape matters more than the host: an endpoint is a URL whose
            // last segment is the registration, and code that parses one should
            // be given something it could really have been handed.
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(152),
            'public_key' => Str::random(87),
            'auth_token' => Str::random(22),
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
        ];
    }

    /**
     * The other big push service, which hands out a different host and a much
     * shorter registration. Here so a test about parsing or storing endpoints
     * has a second real one to work with rather than the same string twice.
     */
    public function firefox(): self
    {
        return $this->state(fn (): array => [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/'.Str::random(120),
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0',
        ]);
    }

    /** One that has already had something sent to it. */
    public function used(): self
    {
        return $this->state(fn (): array => ['last_used_at' => now()->subHour()]);
    }
}
