<?php

use App\Enums\ChannelTicketPolicy;
use App\Enums\WorkspaceRole;
use App\Features\Transfers;
use App\Models\Channel;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A workspace with this user in it. Lives here rather than in one test file so
 * every suite can be run on its own with --filter.
 */
function workspaceWithMember(User $user, WorkspaceRole $role = WorkspaceRole::Member): Workspace
{
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

    return $workspace;
}

/**
 * A workspace that has switched sending files on, and somebody in it who may.
 *
 * The feature is activated by hand rather than left to the factory, and that is
 * the point of it: transfers are off until a workspace says otherwise, so every
 * test that wants one has to say so too.
 *
 * @return array{0: User, 1: Workspace}
 */
function senderInWorkspace(WorkspaceRole $role = WorkspaceRole::Member): array
{
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(Transfers::class);

    return [$user, $workspace];
}

/**
 * Something waiting for somebody who has no account here.
 *
 * The sender is a real member of a workspace that switched the feature on,
 * because both are checked on the way out — a link from a workspace that has
 * since switched sending off must stop working.
 *
 * Here rather than in one test file so every suite can be run on its own.
 *
 * @return array{0: Transfer, 1: Workspace, 2: User}
 */
function waitingTransfer(array $state = [], int $files = 1): array
{
    Storage::fake('local');

    $sender = User::factory()->create();
    $workspace = workspaceWithMember($sender);

    Feature::for($workspace)->activate(Transfers::class);

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'title' => 'Offerte week 32',
        ...$state,
    ]);

    for ($i = 1; $i <= $files; $i++) {
        $transfer->addMedia(UploadedFile::fake()->createWithContent(
            "bestand-{$i}.txt",
            str_repeat('a', 64),
        ))->toMediaCollection(Transfer::FILES);
    }

    return [$transfer->refresh(), $workspace, $sender];
}

function channelWithMember(Workspace $workspace, User $user): Channel
{
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($user->id, ['joined_at' => now()]);

    return $channel;
}

/**
 * A channel that keeps tickets, with a member and a guest in it.
 *
 * The guest is part of the fixture rather than an extra step: a customer
 * channel with nobody external in it is not the case any of these tests are
 * really about.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function ticketFixture(ChannelTicketPolicy $policy = ChannelTicketPolicy::Everyone): array
{
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'ticket_policy' => $policy,
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => WorkspaceRole::Guest->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    return [$member, $guest, $workspace, $channel];
}
