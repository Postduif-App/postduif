<?php

use App\Actions\Chat\PruneInboxItems;
use App\Enums\InboxItemType;
use App\Models\InboxItem;

/**
 * One row for this member, read or not, as old as asked for.
 */
function inboxRow(mixed $user, mixed $channel, mixed $message, ?string $readAgo): InboxItem
{
    return InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => $message->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
        'read_at' => $readAgo === null ? null : now()->sub($readAgo),
    ]);
}

it('takes away what was read long enough ago', function () {
    [$user, $channel, $message] = inboxFixture();

    $old = inboxRow($user, $channel, $message, '40 days');

    expect(app(PruneInboxItems::class)->handle())->toBe(1)
        ->and(InboxItem::find($old->id))->toBeNull();
});

it('keeps what was read recently', function () {
    [$user, $channel, $message] = inboxFixture();

    inboxRow($user, $channel, $message, '3 days');

    // "What was that thing from a few weeks ago" still has an answer here.
    expect(app(PruneInboxItems::class)->handle())->toBe(0)
        ->and(InboxItem::count())->toBe(1);
});

it('never takes away something nobody got round to', function () {
    [$user, $channel, $message] = inboxFixture();

    $forgotten = inboxRow($user, $channel, $message, null);
    $forgotten->forceFill(['created_at' => now()->subYear()])->save();

    /*
     * However old it is. Something still waiting is exactly what an inbox is
     * for, and removing it would turn "you have nothing waiting" into a claim
     * the application cannot back up.
     */
    expect(app(PruneInboxItems::class)->handle())->toBe(0)
        ->and(InboxItem::count())->toBe(1);
});

it('says what it did', function () {
    [$user, $channel, $message] = inboxFixture();

    inboxRow($user, $channel, $message, '40 days');

    $this->artisan('inbox:prune')
        ->expectsOutputToContain('1 inboxregel opgeruimd.')
        ->assertSuccessful();
});
