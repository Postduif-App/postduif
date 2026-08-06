<?php

use App\Actions\Chat\NoticeInChannel;
use App\Actions\Chat\PruneEphemeralNotices;
use App\Models\EphemeralNotice;

use function Pest\Laravel\actingAs;

/**
 * A line in a channel that one person sees and nobody else does.
 *
 * What these tests are really about is the "nobody else" — see the migration
 * for why that lives in a table of its own rather than as a flag on a message.
 */
it('writes a receipt that expires by itself', function () {
    [$member, , , $channel] = ticketFixture();

    $notice = app(NoticeInChannel::class)->handle($channel, $member, 'De workflow is gestart.');

    expect($notice->user_id)->toBe($member->id)
        ->and($notice->expires_at)->not->toBeNull();
});

it('keeps a receipt about something that went wrong until it is dismissed', function () {
    [$member, , , $channel] = ticketFixture();

    $notice = app(NoticeInChannel::class)->handle($channel, $member, 'Dat lukte niet.', keep: true);

    expect($notice->expires_at)->toBeNull();
});

it('sends somebody their own notices and nobody else his', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();

    EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
        'body' => 'Alleen voor het lid',
    ]);
    EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $guest->id,
        'body' => 'Alleen voor de gast',
    ]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('notices', 1)
            ->where('notices.0.body', 'Alleen voor het lid'));

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('notices', 1)
            ->where('notices.0.body', 'Alleen voor de gast'));
});

it('leaves an expired notice out of the page', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    EphemeralNotice::factory()->expired()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('notices', []));
});

it('lets somebody throw away what they were told', function () {
    [$member, , $workspace, $channel] = ticketFixture();
    $notice = EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->delete(route('chat.notices.destroy', [$workspace, $channel, $notice]))
        ->assertRedirect();

    expect(EphemeralNotice::count())->toBe(0);
});

it('is a 404 when the notice belongs to somebody else', function () {
    [$member, $guest, $workspace, $channel] = ticketFixture();
    $theirs = EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $guest->id,
    ]);

    // Not a 403: somebody trying ids should not learn that one of them is a
    // colleague's.
    actingAs($member)
        ->delete(route('chat.notices.destroy', [$workspace, $channel, $theirs]))
        ->assertNotFound();

    expect(EphemeralNotice::count())->toBe(1);
});

it('goes with the channel it hung in', function () {
    [$member, , , $channel] = ticketFixture();
    EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    $channel->delete();

    expect(EphemeralNotice::count())->toBe(0);
});

it('clears out what nobody is going to read again', function () {
    [$member, , , $channel] = ticketFixture();

    EphemeralNotice::factory()->expired()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);
    // One that waits to be dismissed, and waited too long.
    EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
        'created_at' => now()->subDays(PruneEphemeralNotices::KEEP_DAYS + 1),
    ]);
    $current = EphemeralNotice::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    expect(app(PruneEphemeralNotices::class)->handle())->toBe(2)
        ->and(EphemeralNotice::pluck('id')->all())->toBe([$current->id]);
});
