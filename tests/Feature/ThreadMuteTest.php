<?php

use App\Models\InboxItem;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('stops a muted thread reaching your inbox', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $question->muteFor($asker);

    reply($channel, $answerer, $question, 'Ik pak het op');

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('keeps a muted thread quiet however much is said', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $question->muteFor($asker);

    foreach (range(1, 4) as $number) {
        reply($channel, $answerer, $question, "Aanvulling {$number}");
    }

    /*
     * This is the whole difference with closing, which a new reply undoes. If
     * muting behaved the same way it would be a button that does nothing on any
     * thread busy enough to want it.
     */
    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('still names you when somebody writes your handle', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $question->muteFor($asker);

    reply($channel, $answerer, $question, "Even checken @{$asker->username}");

    /*
     * Muting says "stop telling me this thread carried on", not "stop telling
     * me when somebody needs me by name". RecordMentions never consults the
     * mute, and that is the intended split rather than an oversight: a handle
     * is somebody choosing you personally, which is a louder act than a thread
     * simply continuing.
     */
    $rows = InboxItem::where('user_id', $asker->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type->value)->toBe('mention');
});

it('starts telling you again once the mute is lifted', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $question->muteFor($asker);
    reply($channel, $answerer, $question, 'Eerste');

    $question->unmuteFor($asker);
    reply($channel, $answerer, $question, 'Tweede');

    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(1);
});

it('leaves the thread in the sidebar', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    // The sidebar only lists threads that have replies, so there has to be one
    // before there is anything to check.
    reply($channel, $answerer, $question, 'Ik pak het op');

    $question->muteFor($asker);

    // Being quiet about something is not the same as hiding it — that is what
    // closing is for, and the two are offered side by side.
    actingAs($asker)
        ->get(route('chat.show', [$channel->workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('activeThreads', 1)
            ->where('activeThreads.0.muted', true));
});

it('does not undo a mute when the thread is reopened', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    $question->closeFor($asker);
    $question->muteFor($asker);
    $question->reopenFor($asker);

    reply($channel, $answerer, $question, 'Nog iets');

    // Two statements were made, and putting the thread back in the sidebar
    // only takes back one of them.
    expect(InboxItem::where('user_id', $asker->id)->count())->toBe(0);
});

it('lets a member mute and unmute over the route', function () {
    [$asker, , $channel, $question] = threadFixture();

    actingAs($asker)
        ->post(route('chat.threads.mute', [$channel->workspace, $channel, $question]))
        ->assertRedirect();

    expect($question->isMutedFor($asker))->toBeTrue();

    actingAs($asker)
        ->delete(route('chat.threads.unmute', [$channel->workspace, $channel, $question]))
        ->assertRedirect();

    expect($question->fresh()->isMutedFor($asker))->toBeFalse();
});

it('mutes a thread whose opening message was deleted', function () {
    [$asker, $answerer, $channel, $question] = threadFixture();

    reply($channel, $answerer, $question, 'Ik pak het op');

    /*
     * A deleted parent with replies stays in the sidebar as a tombstone — see
     * Message::scopeVisible — so the buttons beside it have to keep working.
     * Implicit binding hides trashed rows by default, which turned muting one
     * of those into a 404.
     */
    $question->delete();

    actingAs($asker)
        ->post(route('chat.threads.mute', [$channel->workspace, $channel, $question]))
        ->assertRedirect();

    expect($question->isMutedFor($asker))->toBeTrue();

    actingAs($asker)
        ->delete(route('chat.threads.unmute', [$channel->workspace, $channel, $question]))
        ->assertRedirect();
});

it('refuses to mute a thread in a channel you cannot see', function () {
    [, , $channel, $question] = threadFixture();

    $outsider = User::factory()->create();

    actingAs($outsider)
        ->post(route('chat.threads.mute', [$channel->workspace, $channel, $question]))
        ->assertForbidden();
});
