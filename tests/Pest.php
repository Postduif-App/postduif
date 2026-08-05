<?php

use App\Actions\Chat\SendMessage;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Transfers;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Role;
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

/*
 * Dutch unless a test says otherwise.
 *
 * Laravel's test client sends "Accept-Language: en-us" of its own accord, and
 * HandleLocale rightly honours it — which would silently answer every
 * assertion in this suite in the wrong language. Pinning it here keeps the
 * suite testing the application rather than the test client's default header;
 * a test about translation overrides it with a withHeader() of its own.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->withHeader('Accept-Language', 'nl'))
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
/**
 * Give a right to some of a workspace's roles, or take it away.
 *
 * Rights live on the role now, so a test that used to set a column on the
 * workspace sets them here instead. Naming no role means every role, which is
 * what "iedereen mag dit" used to mean when it was a dropdown.
 */
function setAbility(Workspace $workspace, WorkspaceAbility $ability, bool $holds, SystemRole ...$roles): void
{
    $keys = array_map(fn (SystemRole $role): string => $role->value, $roles);

    foreach ($workspace->roles()->get() as $role) {
        if ($keys !== [] && ! in_array($role->key, $keys, true)) {
            continue;
        }

        $abilities = collect($role->abilities)->reject(fn (string $held): bool => $held === $ability->value);

        $role->update([
            'abilities' => $holds
                ? [...$abilities->values()->all(), $ability->value]
                : $abilities->values()->all(),
        ]);
    }
}

/**
 * The id of one of a workspace's roles, which is what the screens post.
 *
 * Here rather than in the one test that needed it first: a role is a row now,
 * so anything that changes somebody's standing sends an id, and that is several
 * files.
 */
function roleId(Workspace $workspace, SystemRole $role): int
{
    return $workspace->roles()->where('key', $role->value)->value('id');
}

function workspaceWithMember(User $user, SystemRole $role = SystemRole::Member): Workspace
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
function senderInWorkspace(SystemRole $role = SystemRole::Member): array
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
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    return [$member, $guest, $workspace, $channel];
}

/**
 * A question in a channel, and somebody else who can answer it.
 *
 * @return array{0: User, 1: User, 2: Channel, 3: Message}
 */
function threadFixture(): array
{
    $asker = User::factory()->create();
    $workspace = workspaceWithMember($asker);
    $channel = channelWithMember($workspace, $asker);

    $answerer = User::factory()->create();
    $workspace->members()->attach($answerer->id, ['joined_at' => now()]);
    $channel->members()->attach($answerer->id, ['joined_at' => now()]);

    $question = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $asker->id,
        'body' => 'Wie weet hoe de facturatie hier werkt?',
    ]);

    return [$asker, $answerer, $channel, $question];
}

function reply(mixed $channel, User $author, Message $parent, string $body = 'Ik pak het op'): Message
{
    return app(SendMessage::class)->handle(
        channel: $channel,
        author: $author,
        body: $body,
        parentId: $parent->id,
    );
}

/**
 * A question put to a channel, and somebody else who can answer it.
 *
 * @return array{0: User, 1: User, 2: Poll, 3: PollOption, 4: PollOption}
 */
function pollFixture(bool $allowsMultiple = false): array
{
    $asker = User::factory()->create();
    $workspace = workspaceWithMember($asker);
    $channel = channelWithMember($workspace, $asker);

    $voter = User::factory()->create();
    $workspace->members()->attach($voter->id, ['joined_at' => now()]);
    $channel->members()->attach($voter->id, ['joined_at' => now()]);

    $poll = Poll::create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $asker->id,
        'question' => 'Donderdag of vrijdag?',
        'allows_multiple' => $allowsMultiple,
    ]);

    $options = collect(['Donderdag', 'Vrijdag'])->map(fn (string $label, int $position) => PollOption::create([
        'poll_id' => $poll->id,
        'label' => $label,
        'position' => $position,
    ]));

    return [$asker, $voter, $poll, $options[0], $options[1]];
}

/**
 * The id of a workspace's built-in role, for a factory that has only the id of
 * the workspace to go on.
 *
 * A factory attribute may be handed a workspace that is itself still a factory,
 * so this resolves whatever it is given rather than insisting on a model.
 */
function roleIdFor(mixed $workspace, SystemRole $role): int
{
    $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

    return Role::query()
        ->where('workspace_id', $id)
        ->where('key', $role->value)
        ->value('id');
}
