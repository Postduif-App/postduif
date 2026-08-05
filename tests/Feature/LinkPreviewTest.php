<?php

use App\Actions\Chat\AnnounceLinkPreview;
use App\Actions\Chat\FetchLinkPreview;
use App\Events\LinkPreviewAttached;
use App\Jobs\FetchLinkPreviewJob;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\User;
use App\Support\Dns\HostResolver;
use App\Support\PublicUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A resolver that answers what the test says, so the guard can be checked
 * without asking the machine's own DNS — which on a development box points
 * *.test at localhost, and would refuse every fixture below.
 */
function resolvingTo(string $address): void
{
    app()->bind(HostResolver::class, fn (): HostResolver => new class($address) implements HostResolver
    {
        public function __construct(private readonly string $address) {}

        /** @return array<int, string> */
        public function resolve(string $host): array
        {
            return [$this->address];
        }
    });
}

beforeEach(fn () => resolvingTo('93.184.216.34'));

/**
 * The guard is the whole security surface of this feature: without it, anybody
 * who can type a message can make the server open an address the browser never
 * could.
 */
it('refuses everything that is not a public web address', function (string $url) {
    expect(app(PublicUrl::class)->refuse($url))->not->toBeNull();
})->with([
    'loopback by address' => 'http://127.0.0.1/',
    'loopback shorthand' => 'http://127.1/',
    'loopback as one number' => 'http://2130706433/',
    'loopback in hex' => 'http://0x7f.0x0.0x0.0x1/',
    'private 10' => 'http://10.0.0.5/intern',
    'private 172' => 'http://172.16.4.4/',
    'private 192' => 'http://192.168.1.1/',
    // The single most valuable thing an SSRF can reach on a cloud host.
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'ipv6 loopback' => 'http://[::1]/',
    'file scheme' => 'file:///etc/passwd',
    'gopher scheme' => 'gopher://voorbeeld.nl/',
    'nonsense' => 'niet eens een url',
]);

it('lets an ordinary public address through', function () {
    expect(app(PublicUrl::class)->refuse('https://voorbeeld.test/artikel'))->toBeNull();
});

/**
 * The name is public; what it resolves to is not. A blocklist of hostnames
 * would wave this through, which is why the check is on the addresses.
 */
it('refuses a public name that resolves inward', function () {
    resolvingTo('127.0.0.1');

    expect(app(PublicUrl::class)->refuse('https://ziet-er-goed-uit.nl/'))
        ->toBe('Dit adres ligt binnen ons eigen netwerk.');
});

it('refuses a name that resolves to nothing', function () {
    app()->bind(HostResolver::class, fn (): HostResolver => new class implements HostResolver
    {
        /** @return array<int, string> */
        public function resolve(string $host): array
        {
            return [];
        }
    });

    expect(app(PublicUrl::class)->refuse('https://bestaat-niet.nl/'))
        ->toBe('Deze naam is niet te vinden.');
});

it('stores what the page says about itself', function () {
    Http::fake([
        'voorbeeld.test/*' => Http::response(
            '<html><head><title>Fallback</title>'
            .'<meta property="og:title" content="Een goed artikel">'
            .'<meta property="og:description" content="Waar het over gaat">'
            .'<meta property="og:image" content="https://voorbeeld.test/plaatje.png">'
            .'<meta property="og:site_name" content="Voorbeeld">'
            .'</head><body>…</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        ),
    ]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/artikel');

    expect($preview->title)->toBe('Een goed artikel')
        ->and($preview->description)->toBe('Waar het over gaat')
        ->and($preview->image_url)->toBe('https://voorbeeld.test/plaatje.png')
        ->and($preview->site_name)->toBe('Voorbeeld')
        ->and($preview->isUsable())->toBeTrue();
});

it('falls back to the title tag when a page says nothing else', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Gewone pagina</title></head></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(app(FetchLinkPreview::class)->handle('https://voorbeeld.test/x')->title)
        ->toBe('Gewone pagina');
});

it('has nothing to show for something that is not a web page', function () {
    Http::fake(['*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/bestand.pdf');

    expect($preview->isUsable())->toBeFalse()
        ->and($preview->failed_reason)->toBe('Dit is geen webpagina.');
});

/**
 * A refusal is written down. Retrying on every message that mentions the link
 * is exactly the outgoing traffic this feature must not generate.
 */
it('remembers that a link could not be read', function () {
    $preview = app(FetchLinkPreview::class)->handle('http://127.0.0.1/intern');

    expect($preview->failed_reason)->not->toBeNull();

    Http::fake();

    app(FetchLinkPreview::class)->handle('http://127.0.0.1/intern');

    Http::assertNothingSent();
    expect(LinkPreview::count())->toBe(1);
});

it('fetches the same link only once', function () {
    Http::fake(['*' => Http::response('<title>Een keer</title>', 200, ['Content-Type' => 'text/html'])]);

    app(FetchLinkPreview::class)->handle('https://voorbeeld.test/zelfde');
    app(FetchLinkPreview::class)->handle('https://voorbeeld.test/zelfde');

    Http::assertSentCount(1);
});

it('queues a look-up for a link in a message, when the workspace asked for it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => strtolower((string) Str::ulid()),
        'body' => 'Kijk hier eens: https://voorbeeld.test/artikel',
    ]);

    Queue::assertPushed(
        FetchLinkPreviewJob::class,
        fn (FetchLinkPreviewJob $job): bool => $job->url === 'https://voorbeeld.test/artikel',
    );
});

/** Off by default, and the only setting where the server talks outward. */
it('queues nothing while the workspace has not asked for it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    expect($workspace->link_previews_enabled)->toBeFalse();

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => strtolower((string) Str::ulid()),
        'body' => 'Kijk hier eens: https://voorbeeld.test/artikel',
    ]);

    // Not assertNothingPushed: broadcasting queues a job of its own, and this
    // is a statement about link look-ups rather than about the queue.
    Queue::assertNotPushed(FetchLinkPreviewJob::class);
});

it('looks up one link per message, however many are in it', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => strtolower((string) Str::ulid()),
        'body' => 'https://een.test/a https://twee.test/b https://drie.test/c',
    ]);

    Queue::assertPushed(FetchLinkPreviewJob::class, 1);
});

it('leaves the sentence out of the link', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => strtolower((string) Str::ulid()),
        'body' => 'Zie https://voorbeeld.test/artikel.',
    ]);

    Queue::assertPushed(
        FetchLinkPreviewJob::class,
        fn (FetchLinkPreviewJob $job): bool => $job->url === 'https://voorbeeld.test/artikel',
    );

    expect(Message::count())->toBe(1);
});

it('draws the card once the look-up has produced something', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/artikel',
    ]);

    LinkPreview::create([
        'url' => 'https://voorbeeld.test/artikel',
        'url_hash' => LinkPreview::hash('https://voorbeeld.test/artikel'),
        'title' => 'Een goed artikel',
        'description' => 'Waar het over gaat',
        'fetched_at' => now(),
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.linkPreview.title', 'Een goed artikel')
            ->where('messages.0.linkPreview.url', 'https://voorbeeld.test/artikel'));
});

it('draws nothing while the look-up has not produced anything', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/nooit-opgehaald',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.linkPreview', null));
});

/** A refusal is stored like any other outcome, and shows nothing. */
it('draws nothing for a link that could not be read', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/kapot',
    ]);

    LinkPreview::create([
        'url' => 'https://voorbeeld.test/kapot',
        'url_hash' => LinkPreview::hash('https://voorbeeld.test/kapot'),
        'failed_reason' => 'Dit is geen webpagina.',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.linkPreview', null));
});

it('makes a relative og:image an address a browser can load', function () {
    Http::fake(['*' => Http::response(
        '<html><head><meta property="og:title" content="Artikel">'
        .'<meta property="og:image" content="/og/artikel.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/blog/artikel');

    expect($preview->image_url)->toBe('https://voorbeeld.test/og/artikel.png');
});

it('resolves an og:image beside the page it came from', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Artikel</title>'
        .'<meta property="og:image" content="plaatje.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/blog/artikel');

    expect($preview->image_url)->toBe('https://voorbeeld.test/blog/plaatje.png');
});

it('takes the page its own scheme for a protocol-relative image', function () {
    Http::fake(['*' => Http::response(
        '<html><head><title>Artikel</title>'
        .'<meta property="og:image" content="//voorbeeld.test/og.png"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/artikel');

    expect($preview->image_url)->toBe('https://voorbeeld.test/og.png');
});

it('leaves out a picture that is not an address worth pointing a browser at', function (string $image) {
    Http::fake(['*' => Http::response(
        '<html><head><title>Artikel</title>'
        .'<meta property="og:image" content="'.$image.'"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    $preview = app(FetchLinkPreview::class)->handle('https://voorbeeld.test/artikel');

    expect($preview->title)->toBe('Artikel')
        ->and($preview->image_url)->toBeNull();
})->with([
    'a data URI' => 'data:image/png;base64,iVBORw0KGgo=',
    'something on the inside' => 'http://127.0.0.1/og.png',
]);

it('keeps a workspace that never asked for previews out of the cache', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/artikel',
    ]);

    // Fetched for somebody else entirely: the cache is one table for the whole
    // platform, and this workspace said it does not do this.
    LinkPreview::create([
        'url' => 'https://voorbeeld.test/artikel',
        'url_hash' => LinkPreview::hash('https://voorbeeld.test/artikel'),
        'title' => 'Een goed artikel',
        'fetched_at' => now(),
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.linkPreview', null));
});

it('tells the conversation once the look-up is in', function () {
    Event::fake([LinkPreviewAttached::class]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/artikel',
    ]);

    Http::fake(['*' => Http::response(
        '<html><head><meta property="og:title" content="Artikel"></head></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);

    (new FetchLinkPreviewJob('https://voorbeeld.test/artikel'))
        ->handle(app(FetchLinkPreview::class), app(AnnounceLinkPreview::class));

    Event::assertDispatched(
        LinkPreviewAttached::class,
        fn (LinkPreviewAttached $event): bool => $event->message->is($message),
    );
});

it('says nothing to a workspace that does not draw cards', function () {
    Event::fake([LinkPreviewAttached::class]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/artikel',
    ]);

    $preview = LinkPreview::create([
        'url' => 'https://voorbeeld.test/artikel',
        'url_hash' => LinkPreview::hash('https://voorbeeld.test/artikel'),
        'title' => 'Artikel',
        'fetched_at' => now(),
    ]);

    app(AnnounceLinkPreview::class)->handle($preview);

    Event::assertNotDispatched(LinkPreviewAttached::class);
});

it('says nothing about a message that has moved out of sight', function () {
    Event::fake([LinkPreviewAttached::class]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->update(['link_previews_enabled' => true]);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Zie https://voorbeeld.test/artikel',
        'created_at' => now()->subHours(3),
    ]);

    $preview = LinkPreview::create([
        'url' => 'https://voorbeeld.test/artikel',
        'url_hash' => LinkPreview::hash('https://voorbeeld.test/artikel'),
        'title' => 'Artikel',
        'fetched_at' => now(),
    ]);

    app(AnnounceLinkPreview::class)->handle($preview);

    Event::assertNotDispatched(LinkPreviewAttached::class);
});

it('keeps one look-up per link in flight, not one per message', function () {
    // The interface is the whole fix: uniqueId() on its own is a method
    // Laravel never calls, so without it ten channels given the same new link
    // meant ten outgoing requests.
    expect(FetchLinkPreviewJob::class)->toImplement(ShouldBeUnique::class)
        ->and((new FetchLinkPreviewJob('https://voorbeeld.test/a'))->uniqueId())
        ->toBe('https://voorbeeld.test/a');
});
