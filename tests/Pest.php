<?php

use App\Actions\Chat\PresentMessage;
use App\Actions\Chat\SendMessage;
use App\Actions\Workflows\RunWorkflow;
use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Contracts as ContractsFeature;
use App\Features\Documents as DocumentsFeature;
use App\Features\Forms;
use App\Features\Huddles as HuddlesFeature;
use App\Features\SecretRequests;
use App\Features\SharedChannels;
use App\Features\Timeclock;
use App\Features\Transfers;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\ApiToken;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Role;
use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\Workspace;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Minishlink\WebPush\WebPush;
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

/**
 * Skip a test that has to read server-rendered markup, when nothing is there
 * to render it.
 *
 * Inertia's SSR is switched on in config and renders through a separate
 * process, and Inertia falls back to client-side rendering when that process
 * is not answering. That fallback is right for a browser and useless to a test
 * that asserts on the HTML — the response is then a div and a blob of JSON,
 * and the assertion fails for a reason that has nothing to do with the code.
 *
 * So: skipped rather than failed. A test that says "no renderer" is honest
 * about what it did not check; one that goes red sends somebody looking for a
 * bug that is not there.
 */
function skipWithoutSsr(): void
{
    $url = parse_url((string) config('inertia.ssr.url'));

    $socket = @fsockopen($url['host'] ?? '127.0.0.1', (int) ($url['port'] ?? 13714), $code, $message, 0.5);

    if ($socket === false) {
        test()->markTestSkipped('The Inertia SSR renderer is not running, so there is no server-rendered HTML to read.');
    }

    fclose($socket);
}

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
    return Role::idFor($workspace, $role);
}

function workspaceWithMember(User $user, SystemRole $role = SystemRole::Member): Workspace
{
    $workspace = Workspace::factory()->create();

    joinWorkspace($workspace, $user, $role);

    return $workspace;
}

/**
 * Put somebody in a workspace, in one of its built-in roles.
 *
 * The membership points at a role row; the old string column beside it is on
 * its way out. Going through here rather than writing the pivot by hand at
 * sixty-odd call sites means the next change to how a role is held is one
 * edit — which is exactly the change being made now.
 */
function joinWorkspace(Workspace $workspace, User $user, SystemRole $role = SystemRole::Member): void
{
    $workspace->members()->attach($user->id, [
        'workspace_role_id' => roleId($workspace, $role),
        'joined_at' => now(),
    ]);
}

/**
 * A real PDF on disk, because a fake one proves nothing here.
 *
 * UploadedFile::fake()->create('x.pdf') writes zeroes with a .pdf on the end,
 * and every check in this feature is about the bytes rather than the name — it
 * would be refused at the first gate for the wrong reason, and a test built on
 * it would pass while the real thing was broken.
 *
 * @param  string|null  $javascript  Something to embed, for the tests about
 *                                   what does not survive the trip.
 */
function realPdf(int $pages = 3, ?string $javascript = null): string
{
    $pdf = new TCPDF;

    for ($page = 1; $page <= $pages; $page++) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Testcontract pagina '.$page);
    }

    if ($javascript !== null) {
        $pdf->IncludeJS($javascript);
    }

    $path = tempnam(sys_get_temp_dir(), 'pest-contract-').'.pdf';

    $pdf->Output($path, 'F');

    return $path;
}

function uploadedPdf(int $pages = 3, ?string $javascript = null): UploadedFile
{
    return new UploadedFile(
        realPdf($pages, $javascript),
        'huurovereenkomst.pdf',
        'application/pdf',
        test: true,
    );
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

/**
 * A platform that somebody has already set up.
 *
 * For tests about the public screens — the home page, the login and sign-up
 * forms — which are the three addresses RedirectToInstallation takes over while
 * the platform is empty. They were written against a database with nothing in
 * it, which is now the one state in which those pages are not the pages that
 * answer.
 *
 * One account is the whole of it: see Installation, which reads an empty
 * platform as one with no accounts rather than one with no workspaces. Nobody
 * is signed in as this person and no workspace is made for them — a visitor who
 * is not logged in has to stay a visitor, which is exactly what several of
 * these tests are checking.
 */
function installedPlatform(): User
{
    return User::factory()->create();
}

function channelWithMember(Workspace $workspace, User $user): Channel
{
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($user->id, ['joined_at' => now()]);

    return $channel;
}

/**
 * A beheerder of a workspace that has workflows switched on.
 *
 * Here rather than in the test file that first needed it: a fixture reached
 * from a second file is one an isolated run of that file has to hope somebody
 * else loaded.
 *
 * @return array{0: User, 1: Workspace}
 */
function workflowBeheerder(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    return [$user, $workspace];
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
    joinWorkspace($workspace, $guest, SystemRole::Guest);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    return [$member, $guest, $workspace, $channel];
}

/**
 * A channel that keeps documents, with a member and a guest in it.
 *
 * The guest is part of the fixture rather than an extra step, for the same
 * reason the ticket one has one: what these tests are really about is the line
 * between somebody from the house and somebody from outside, and a fixture
 * without the second half cannot show where it runs.
 *
 * The workspace feature is switched on here as well. It is off for a fresh
 * workspace in these tests, and a fixture that produced a channel whose document
 * routes all 404 would be a fixture every test had to remember to finish.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function documentFixture(ChannelDocumentPolicy $policy = ChannelDocumentPolicy::Everyone): array
{
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    Feature::for($workspace)->activate(DocumentsFeature::class);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
        'document_policy' => $policy,
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);
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

/*
|--------------------------------------------------------------------------
| Fixtures reached from more than one file
|--------------------------------------------------------------------------
|
| Each of these was written in the test file that first needed it and then
| borrowed by a second — which works only for as long as PHP happens to have
| loaded the first file. Under the parallel runner it does not: a worker loads
| only the files it was handed, so the borrower dies on "call to undefined
| function", and which files land together changes from run to run. The same
| goes for running one file with --filter.
|
| So they live here, where every file can see them, alongside the fixtures that
| were already moved for exactly that reason.
|
*/

/**
 * A workspace with a prikbord, somebody who may read it, and a guest who may
 * not.
 *
 * The channel comes along because the rail is only ever drawn on a real chat
 * screen: chat.index redirects to whichever channel was busiest, so a test that
 * wants to look at the sidebar has to have somewhere for it to land.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function boardFixture(): array
{
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);
    $channel->members()->attach([$member->id, $guest->id], ['joined_at' => now()]);

    return [$member, $guest, $workspace, $channel];
}

/**
 * A channel with its creator and one ordinary member in it.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function settingsFixture(): array
{
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
    ]);
    $channel->members()->attach($creator->id, ['joined_at' => now()]);

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return [$creator, $member, $workspace, $channel];
}

/**
 * A member, a channel they belong to, and a message in it.
 *
 * @return array{0: User, 1: Channel, 2: Message}
 */
function inboxFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    return [$user, $channel, $message];
}

/**
 * A workspace that keeps forms, and somebody in it who may write one.
 *
 * The feature is switched on by hand, which is the point of it: forms are off
 * until a workspace says otherwise, so every test that wants one says so too.
 *
 * @return array{0: User, 1: Workspace}
 */
function formAuthor(SystemRole $role = SystemRole::Admin): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(Forms::class);

    return [$user, $workspace];
}

/**
 * A workspace that switched asking on, with a channel and somebody in it.
 *
 * Turned on by hand every time, like the transfer fixtures: it is off by
 * default, and that is the point of it being a decision.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function requesterInChannel(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Feature::for($workspace)->activate(SecretRequests::class);

    return [$user, $workspace, $channel];
}

/**
 * A request waiting for answers, and a guest who can reach it.
 *
 * The guest is the fixture rather than an extra step: the customer holding the
 * credentials is who this whole feature is for.
 *
 * @return array{0: SecretRequest, 1: SecretRequestKey, 2: SecretRequestKey, 3: User, 4: User}
 */
function fillableRequest(array $state = []): array
{
    [$requester, $workspace, $channel] = requesterInChannel();

    $request = SecretRequest::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $requester->id,
        ...$state,
    ]);

    $password = SecretRequestKey::factory()->create([
        'secret_request_id' => $request->id,
        'name' => 'DB_PASSWORD',
        'position' => 0,
    ]);
    $token = SecretRequestKey::factory()->create([
        'secret_request_id' => $request->id,
        'name' => 'API_TOKEN',
        'position' => 1,
    ]);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    return [$request->refresh(), $password, $token, $guest, $requester];
}

/**
 * Somebody in a workspace that has the clock switched on.
 *
 * Activated by hand, like the transfers helper it is modelled on, and for the
 * same reason: tijdregistratie is off until a workspace says otherwise, so a
 * test that wants one has to say so too.
 *
 * @return array{0: User, 1: Workspace}
 */
function clockingMember(SystemRole $role = SystemRole::Member): array
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(Timeclock::class);

    return [$user, $workspace];
}

/**
 * A workspace with workflows switched on, a beheerder who owns them, and a
 * channel that beheerder is in.
 *
 * @return array{0: Workflow, 1: Workspace, 2: User, 3: Channel}
 */
function workflowWithChannel(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $channel = channelWithMember($workspace, $owner);

    $workflow = Workflow::factory()->enabled()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Storingsmelder',
    ]);

    return [$workflow, $workspace, $owner, $channel];
}

/** Run one step and hand back the run, so a test can read what happened. */
function runStep(Workflow $workflow, string $action, array $config, array $context = []): WorkflowRun
{
    WorkflowStep::factory()->for($workflow)->at(0)->doing($action, $config)->create();

    $run = WorkflowRun::factory()->for($workflow)->create([
        'context' => $context + ['depth' => 1],
    ]);

    app(RunWorkflow::class)->handle($run);

    return $run->fresh();
}

/** A switched-on workflow with one harmless step, so a run has something to do. */
function listeningWorkflow(User $owner, string $trigger, array $config, Channel $target): Workflow
{
    $workflow = Workflow::factory()->enabled()->triggeredBy($trigger, $config)->create([
        'workspace_id' => $target->workspace_id,
        'created_by' => $owner->id,
        'name' => 'Melder',
    ]);

    WorkflowStep::factory()->for($workflow)->at(0)->doing('get-channel-info', [
        'channel_id' => $target->id,
    ])->create();

    return $workflow;
}

/**
 * A member and a token that speaks for them.
 *
 * @return array{0: User, 1: string}
 */
function tokenFor(User $user): array
{
    $token = new ApiToken(['user_id' => $user->id, 'name' => 'Script op mijn laptop']);
    $token->user_id = $user->id;
    $plain = $token->regenerateToken();
    $token->save();

    return [$user, $plain];
}

/**
 * A channel with somebody in it. Polls are on by default, so nothing is
 * switched on here — that is the point of the default.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function pollChannel(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

/**
 * A channel with huddles switched on, a member and a guest in it.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function huddleFixture(): array
{
    [$member, $guest, $workspace, $channel] = ticketFixture();

    Feature::for($workspace)->activate(HuddlesFeature::class);

    return [$member, $guest, $workspace, $channel];
}

/** A message as the screens receive it. */
function present(Message $message): array
{
    return app(PresentMessage::class)->handle($message);
}

/**
 * Throw away the faked HTTP client and everything stubbed on it.
 *
 * Http::fake() appends to the stubs already registered and the first match
 * wins, so a test that wants a busier Reverb than the one its beforeEach set up
 * would silently get the quiet one. Clearing the facade's cache is not enough
 * on its own — the factory is a container singleton, so the same instance comes
 * straight back — which is why the binding goes too.
 */
function freshHttpClient(): void
{
    app()->forgetInstance(HttpFactory::class);

    Http::clearResolvedInstances();
}

/**
 * Answer every push request with the given responses, in order.
 *
 * The library builds its own Guzzle client, so the seam is the client options
 * it passes through: a mock handler there keeps the RFC 8291 encryption in the
 * test — that is the part worth exercising — without a real request leaving the
 * machine.
 *
 * Lives here rather than in the test file that first needed it, because Pest's
 * parallel runner splits test files across worker processes — a plain function
 * declared in one test file is only defined in whichever process happens to
 * load that file, so another test relying on it would fail at random depending
 * on how the split fell. Everything in this file is loaded by every worker.
 *
 * @param  array<int, Response>  $responses
 */
function fakePushService(array $responses): void
{
    app()->bind(WebPush::class, fn ($app, array $parameters): WebPush => new WebPush(
        $parameters['auth'] ?? [],
        [],
        30,
        ['handler' => HandlerStack::create(new MockHandler($responses))],
    ));
}

/**
 * Stand in for a running Reverb.
 *
 * Keyed by workspace slug rather than by full channel name, because that is
 * what a reader of the test cares about; the prefix is the thing under test and
 * is written out once, here.
 *
 * @param  array<string, list<int>>  $rosters  Slug to the account ids on it.
 */
function reverbIsUp(int $connections, array $rosters = []): void
{
    freshHttpClient();
    Http::preventStrayRequests();

    $channels = [];

    foreach ($rosters as $slug => $ids) {
        $channels['presence-chat.workspace.'.$slug] = $ids;
    }

    Http::fake(function (ClientRequest $request) use ($connections, $channels) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/connections')) {
            return Http::response(['connections' => $connections]);
        }

        if (str_ends_with($path, '/users')) {
            $channel = Str::between($path, '/channels/', '/users');

            return Http::response([
                'users' => array_map(fn (int $id): array => ['id' => $id], $channels[$channel] ?? []),
            ]);
        }

        /*
         * Reverb keys the listing by channel name and only lists channels with
         * somebody on them, which is why an empty roster never appears here.
         */
        return Http::response([
            'channels' => array_map(fn (): array => [], array_filter($channels)),
        ]);
    });
}

/**
 * Stand in for a Reverb that is not answering.
 *
 * Takes a status rather than assuming one, because the two ways this goes wrong
 * read differently to whoever is debugging: nothing listening on the port, and
 * a server that will not accept the app's credentials.
 */
function reverbIsDown(int $status = 500): void
{
    freshHttpClient();
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(status: $status));
}

/**
 * A contract with a document on it, ready to be sent.
 *
 * Here rather than in the file that first needed it, and that is the whole
 * point of the move: two suites use it, and a helper declared inside a test
 * file only exists once that file has been loaded. The parallel runner splits
 * files across processes, so a suite borrowing another suite's helper passes or
 * fails depending on which process it landed in — which is not a thing anybody
 * should have to debug twice.
 *
 * @return array{0: User, 1: Workspace, 2: Contract}
 */
function sendableContract(array $state = []): array
{
    Storage::fake('local');
    Mail::fake();

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $contract = Contract::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        ...$state,
    ]);

    // A document has to be on it: a contract without one is a link to nothing.
    $contract->addMedia(UploadedFile::fake()->create('contract.pdf', 20))
        ->toMediaCollection(Contract::SOURCE);

    return [$author, $workspace, $contract];
}

/**
 * A workspace that has switched contracts on, and somebody in it who may send
 * them.
 *
 * The feature is activated by hand rather than left to the factory, and that is
 * the point of it: contracts are off until a workspace says otherwise, so every
 * test that wants one has to say so too.
 *
 * Here for the same reason as sendableContract() above: a second suite borrows
 * it, and under the parallel runner that only works when both files happen to
 * land in the same process.
 *
 * @return array{0: User, 1: Workspace}
 */
function contractSenderInWorkspace(SystemRole $role = SystemRole::Admin): array
{
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);

    Feature::for($workspace)->activate(ContractsFeature::class);

    return [$user, $workspace];
}

/**
 * Two workspaces that both offer shared channels, and a channel in the first.
 *
 * Both sides switched on by hand rather than by the factory, for the same
 * reason the transfer fixture does it: the feature ships off, and a fixture
 * that quietly turned it on would hide the day somebody changes that default.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: User, 4: Workspace}
 */
function sharedChannelFixture(): array
{
    $host = User::factory()->create();
    $hostWorkspace = workspaceWithMember($host, SystemRole::Admin);

    $guest = User::factory()->create();
    $guestWorkspace = workspaceWithMember($guest, SystemRole::Admin);

    Feature::for($hostWorkspace)->activate(SharedChannels::class);
    Feature::for($guestWorkspace)->activate(SharedChannels::class);

    return [$host, $hostWorkspace, channelWithMember($hostWorkspace, $host), $guest, $guestWorkspace];
}
