<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        // The home page belongs to a platform that exists; an empty one sends
        // this address to the onboarding screen.
        User::factory()->create();

        $response = $this->get(route('home'));

        $response->assertOk();
    }
}
