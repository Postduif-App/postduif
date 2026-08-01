<?php

use App\Models\Channel;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * A ticket somebody may comment on, in a channel they are in.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Ticket}
 */
function commentableTicket(): array
{
    // The shared fixture, so the channel actually keeps tickets — a channel
    // that does not is a 403 for reasons that have nothing to do with files.
    [$user, , $workspace, $channel] = ticketFixture();

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $user->id,
    ]);

    return [$user, $workspace, $channel, $ticket];
}

/**
 * A fake upload is empty, and an empty file is detected as
 * "application/x-empty" — which no workspace allows, so it would be refused for
 * a reason that has nothing to do with what is being tested.
 */
function fakeImage(string $name = 'schermafbeelding.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 40, 40);
}

it('hangs a file on a comment', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Zie de screenshot', 'attachments' => [fakeImage()]],
    )->assertRedirect();

    $attachment = TicketCommentAttachment::sole();

    expect($attachment->name)->toBe('schermafbeelding.png')
        ->and($attachment->isImage())->toBeTrue()
        ->and(Storage::disk($attachment->disk)->exists($attachment->path))->toBeTrue();
});

/** A file on its own is a reply, the same way it is a message. */
it('takes a file with no words at all', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['attachments' => [fakeImage()]],
    )->assertRedirect();

    expect(TicketComment::count())->toBe(1)
        ->and(TicketCommentAttachment::count())->toBe(1);
});

it('hands the file to somebody who may see the channel', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Kijk', 'attachments' => [fakeImage()]],
    );

    $attachment = TicketCommentAttachment::sole();

    actingAs($user)->get(route('chat.tickets.comments.attachments.show', [
        $workspace, $channel, $ticket, $attachment->comment, $attachment,
    ]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

/**
 * The whole reason the file sits on a private disk: this is the only way to it,
 * and it asks what the channel asks.
 */
it('refuses the file to somebody who may not see the channel', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Kijk', 'attachments' => [fakeImage()]],
    );

    $channel->update(['type' => 'private']);
    $attachment = TicketCommentAttachment::sole();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($outsider)->get(route('chat.tickets.comments.attachments.show', [
        $workspace, $channel, $ticket, $attachment->comment, $attachment,
    ]))->assertForbidden();
});

/**
 * The route sits on our own origin, so an uploaded page served inline would run
 * its script as us. Asking for a download is always granted; asking to see
 * something in place is not.
 */
it('never shows an uploaded page in place', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    $comment = TicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
    ]);

    $attachment = $comment->attachments()->create([
        'disk' => 'local',
        'path' => 'ticket-comments/'.$comment->id.'/kwaad.html',
        'name' => 'kwaad.html',
        'mime_type' => 'text/html',
        'size' => 10,
    ]);

    Storage::disk('local')->put($attachment->path, '<script>alert(1)</script>');

    actingAs($user)->get(route('chat.tickets.comments.attachments.show', [
        $workspace, $channel, $ticket, $comment, $attachment,
    ]))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="kwaad.html"');
});

it('refuses a file type this workspace does not take', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    $workspace->update(['allowed_attachment_types' => ['documents']]);

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Toch', 'attachments' => [fakeImage()]],
    )->assertSessionHasErrors('attachments.0');

    expect(TicketCommentAttachment::count())->toBe(0);
});

it('refuses a file at all where the workspace shares none', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    $workspace->update(['uploads_enabled' => false]);

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Toch', 'attachments' => [fakeImage()]],
    )->assertSessionHasErrors('attachments');

    expect(TicketCommentAttachment::count())->toBe(0);
});

/** A file left on disk after its comment is gone is the one thing that must not happen. */
it('takes the file off the disk with its comment', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Kijk', 'attachments' => [fakeImage()]],
    );

    $attachment = TicketCommentAttachment::sole();
    $path = $attachment->path;

    $attachment->delete();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('sends the files along with the ticket', function () {
    [$user, $workspace, $channel, $ticket] = commentableTicket();

    actingAs($user)->post(
        route('chat.tickets.comments.store', [$workspace, $channel, $ticket]),
        ['body' => 'Zie bijlage', 'attachments' => [fakeImage()]],
    );

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?view=tickets&ticket='.$ticket->number)
        ->assertInertia(fn ($page) => $page
            ->has('ticket.timeline.0.attachments', 1)
            ->where('ticket.timeline.0.attachments.0.name', 'schermafbeelding.png')
            ->where('ticket.timeline.0.attachments.0.isImage', true)
            // Sent even though nothing fills it: the renderer is shared with
            // the conversation, and a shape that is "the same except one key"
            // is the one that trips it up.
            ->where('ticket.timeline.0.attachments.0.previewUrl', null)
        );
});
