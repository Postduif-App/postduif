<?php

use App\Http\Controllers\BroadcastMessageController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelFavoriteController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChannelMuteController;
use App\Http\Controllers\ChannelSectionController;
use App\Http\Controllers\ChannelTagController;
use App\Http\Controllers\ChannelWebhookController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\InviteLinkController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\MessageBookmarkController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageDeletionController;
use App\Http\Controllers\MessageEditController;
use App\Http\Controllers\MessageForwardController;
use App\Http\Controllers\MessagePinController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ScheduledMessageController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecretRequestController;
use App\Http\Controllers\ThreadClosureController;
use App\Http\Controllers\TicketCommentAttachmentController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WorkspaceBookmarkController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceMentionController;
use App\Http\Controllers\WorkspaceTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('app')->group(function () {
    Route::get('/', [ChatController::class, 'home'])->name('chat.home');

    /**
     * The workspace slug is a wildcard directly under /app, so it could swallow
     * /app/settings. Two things keep that from happening: settings.php is
     * registered first, and the pattern below refuses "settings" outright — so
     * a workspace can never claim the slug either.
     */
    Route::prefix('{workspace}')
        ->name('chat.')
        ->where(['workspace' => '(?!settings$)[a-z0-9][a-z0-9-]*'])
        ->group(function () {
            Route::get('/', [ChatController::class, 'index'])->name('index');
            Route::get('search', SearchController::class)->name('search');
            Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');

            /**
             * Inviting people lives here rather than under settings: it is what
             * you reach for while looking at the channel you want somebody in,
             * not something you go to a separate screen for.
             */
            Route::post('invitations', [WorkspaceInvitationController::class, 'store'])
                ->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [WorkspaceInvitationController::class, 'resend'])
                ->name('invitations.resend');
            Route::delete('invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
                ->name('invitations.destroy');

            /*
             * The same thing for somebody whose address you do not know. The
             * public half — following the link — is not here: it has to be
             * reachable while signed out, so it lives next to invitations.show
             * in web.php.
             */
            Route::middleware('feature:invite-links')->group(function () {
                Route::post('invite-links', [InviteLinkController::class, 'store'])
                    ->name('invite-links.store');
                Route::delete('invite-links/{invite_link}', [InviteLinkController::class, 'destroy'])
                    ->name('invite-links.destroy');
            });

            /*
             * A question put to a channel. Under the channel because that is
             * where it is asked; voting is not, because a poll's id is enough
             * to find it and the vote is about the poll rather than the room.
             */
            Route::middleware('feature:polls')->group(function () {
                Route::post('c/{channel}/polls', [PollController::class, 'store'])
                    ->name('polls.store');
                Route::get('polls/{poll}', [PollController::class, 'show'])
                    ->name('polls.show');
                Route::post('polls/{poll}/options/{option}', [PollController::class, 'vote'])
                    ->name('polls.vote');
                Route::delete('polls/{poll}', [PollController::class, 'close'])
                    ->name('polls.close');

                // Undoing that. Its own address rather than the close route
                // with a flag: closing and reopening are separate acts, and
                // a DELETE that sometimes un-deletes reads as neither.
                Route::post('polls/{poll}/reopen', [PollController::class, 'reopen'])
                    ->name('polls.reopen');
            });

            /*
             * Asking somebody for a password or a key, instead of having them
             * paste it into the conversation. Asking is done from a channel,
             * so it sits under one; answering is not, and lives in
             * settings-free territory of its own below.
             */
            Route::middleware('feature:secret-requests')->group(function () {
                Route::post('c/{channel}/secrets', [SecretRequestController::class, 'store'])
                    ->name('secrets.store');
                Route::delete('secrets/{secretRequest}', [SecretRequestController::class, 'destroy'])
                    ->name('secrets.destroy');
            });
            /**
             * A DM is not created through channels.store: StoreChannelRequest
             * refuses type=dm, and rightly so — there is no name, slug or topic
             * to validate. Starting one is picking a person, not filling a form.
             */
            Route::get('dm/candidates', [DirectMessageController::class, 'index'])
                ->name('directs.candidates');
            Route::post('dm', [DirectMessageController::class, 'store'])->name('directs.store');

            /**
             * One message into several channels at once — see
             * BroadcastMessageController. Not under c/{channel}: it belongs to
             * no single channel, which is the point of it.
             */
            Route::post('broadcast', [BroadcastMessageController::class, 'store'])
                ->name('broadcast.store');

            /**
             * Clears the conversation out of your own sidebar and nobody
             * else's. Delete because that is the gesture, not because anything
             * is removed — see HideDirectMessage.
             */
            Route::delete('dm/{channel}', [DirectMessageController::class, 'destroy'])
                ->name('directs.destroy');

            /**
             * Every ticket in the workspace, across the channels this member
             * can see. Not under c/{channel}: it belongs to no channel, which
             * is the whole point of it.
             */
            Route::get('tickets', [WorkspaceTicketController::class, 'index'])
                ->middleware('feature:tickets')
                ->name('tickets.index');

            /*
             * Every place this member was named. Not under c/{channel} for the
             * same reason the ticket list is not: being named is something that
             * happens to a person across channels, not inside one.
             */
            Route::get('mentions', [WorkspaceMentionController::class, 'index'])
                ->name('mentions.index');

            // Everything this member set aside, across channels — see
            // WorkspaceBookmarkController.
            Route::get('saved', [WorkspaceBookmarkController::class, 'index'])
                ->middleware('feature:saved-messages')
                ->name('saved.index');

            /*
             * The groups somebody made in their own sidebar. Not under a
             * channel: a section spans them, and belongs to the member rather
             * than to any one conversation.
             */
            Route::post('sections', [ChannelSectionController::class, 'store'])
                ->name('sections.store');
            Route::put('sections', [ChannelSectionController::class, 'update'])
                ->name('sections.update');
            Route::delete('sections/{section}', [ChannelSectionController::class, 'destroy'])
                ->name('sections.destroy');

            Route::get('c/{channel}', [ChatController::class, 'show'])->name('show');
            Route::patch('c/{channel}', [ChannelController::class, 'update'])->name('channels.update');

            /*
             * Not the same as dm/{channel} above: that one hides a conversation
             * for the member who asked, this one takes the channel away for
             * everybody. A DM never reaches it — the policy refuses.
             */
            Route::delete('c/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

            Route::post('c/{channel}/join', [ChannelController::class, 'join'])->name('channels.join');

            /*
             * The reversible neighbour of the delete above. One route for both
             * directions: it is one decision with a state, and two would let
             * the browser ask for a transition that already happened.
             */
            Route::post('c/{channel}/archive', [ChannelController::class, 'archive'])
                ->name('channels.archive');

            /*
             * Muting is about one member's own attention, so it sits on the
             * membership rather than on the channel — see ChannelMuteController.
             */
            /*
             * Keeping a channel at the top of your own sidebar — the same kind
             * of decision as muting, and stored on the same membership row.
             */
            Route::post('c/{channel}/favorite', [ChannelFavoriteController::class, 'store'])
                ->name('channels.favorite');
            Route::delete('c/{channel}/favorite', [ChannelFavoriteController::class, 'destroy'])
                ->name('channels.unfavorite');

            Route::post('c/{channel}/mute', [ChannelMuteController::class, 'store'])->name('channels.mute');
            Route::delete('c/{channel}/mute', [ChannelMuteController::class, 'destroy'])->name('channels.unmute');
            Route::get('c/{channel}/members', [ChannelMemberController::class, 'index'])->name('channels.members.index');
            Route::post('c/{channel}/members', [ChannelMemberController::class, 'store'])->name('channels.members.store');
            Route::delete('c/{channel}/members', [ChannelMemberController::class, 'destroy'])->name('channels.members.destroy');
            Route::delete('c/{channel}/members/{user}', [ChannelMemberController::class, 'remove'])->name('channels.members.remove');
            /**
             * The buttons in the bar above the conversation. Scoped, so a link
             * id from another channel is a 404 rather than something every
             * method has to remember to check.
             */
            /**
             * The whole set of labels at once — see ChannelTagController.
             */
            Route::put('c/{channel}/tags', [ChannelTagController::class, 'update'])
                ->name('channels.tags.update');

            Route::post('c/{channel}/links', [ChannelLinkController::class, 'store'])
                ->name('channels.links.store');
            Route::put('c/{channel}/links/order', [ChannelLinkController::class, 'reorder'])
                ->name('channels.links.reorder');
            Route::patch('c/{channel}/links/{link}', [ChannelLinkController::class, 'update'])
                ->scopeBindings()
                ->name('channels.links.update');
            Route::delete('c/{channel}/links/{link}', [ChannelLinkController::class, 'destroy'])
                ->scopeBindings()
                ->name('channels.links.destroy');

            /* Het beheer; het endpoint dat een webhook gebruikt zit in api.php. */
            Route::middleware('feature:webhooks')->group(function () {
                Route::get('c/{channel}/webhooks', [ChannelWebhookController::class, 'index'])->name('channels.webhooks.index');
                Route::post('c/{channel}/webhooks', [ChannelWebhookController::class, 'store'])->name('channels.webhooks.store');

                Route::patch('c/{channel}/webhooks/{webhook}', [ChannelWebhookController::class, 'update'])
                    ->scopeBindings()
                    ->name('channels.webhooks.update');

                Route::post('c/{channel}/webhooks/{webhook}/token', [ChannelWebhookController::class, 'regenerate'])
                    ->scopeBindings()
                    ->name('channels.webhooks.regenerate');

                // Scoped, so a webhook id belonging to another channel is a 404
                // rather than something the controller has to remember to check.
                Route::delete('c/{channel}/webhooks/{webhook}', [ChannelWebhookController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('channels.webhooks.destroy');
            });

            /**
             * Tickets are read through chat.show with ?view=tickets, the same
             * way an open thread travels in the query string. Only the writes
             * live on routes of their own.
             */
            Route::middleware('feature:tickets')->group(function () {
                Route::post('c/{channel}/tickets', [TicketController::class, 'store'])->name('tickets.store');

                // Scoped, so a ticket number from another channel is a 404 rather
                // than something every method has to remember to check.
                Route::patch('c/{channel}/tickets/{ticket}', [TicketController::class, 'update'])
                    ->scopeBindings()
                    ->name('tickets.update');

                Route::post('c/{channel}/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
                    ->scopeBindings()
                    ->name('tickets.comments.store');

                Route::patch('c/{channel}/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'update'])
                    ->scopeBindings()
                    ->name('tickets.comments.update');

                /*
                 * The only way to a file on a ticket comment: they sit on the
                 * private disk, and this asks the ChannelPolicy first — see
                 * TicketCommentAttachmentController.
                 */
                Route::get(
                    'c/{channel}/tickets/{ticket}/comments/{comment}/files/{attachment}',
                    [TicketCommentAttachmentController::class, 'show'],
                )
                    ->scopeBindings()
                    ->name('tickets.comments.attachments.show');

                Route::delete('c/{channel}/tickets/{ticket}/comments/{comment}', [TicketCommentController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('tickets.comments.destroy');
            });

            /**
             * Written now, said later. Scoped, so an id from another channel is
             * a 404 rather than something each method has to remember.
             */
            Route::post('c/{channel}/scheduled', [ScheduledMessageController::class, 'store'])
                ->middleware('feature:scheduled-messages')
                ->name('channels.scheduled.store');

            /*
             * Changing and cancelling stay open even where scheduling has since
             * been switched off, and only these two do. What is already in the
             * queue was parked when it was allowed and will still go out; a
             * member who can see it waiting but cannot call it back has been
             * handed a worse deal than one who was never offered the feature.
             */
            Route::patch('c/{channel}/scheduled/{scheduled_message}', [ScheduledMessageController::class, 'update'])
                ->scopeBindings()
                ->name('channels.scheduled.update');
            Route::delete('c/{channel}/scheduled/{scheduled_message}', [ScheduledMessageController::class, 'destroy'])
                ->scopeBindings()
                ->name('channels.scheduled.destroy');

            Route::post('c/{channel}/messages', [MessageController::class, 'store'])->name('messages.store');

            // Scoped binding resolves {message} through the channel, so a
            // message id from another channel is a 404 rather than a check the
            // controller has to remember to make.
            Route::post('c/{channel}/messages/{message}/reactions', [ReactionController::class, 'store'])
                ->scopeBindings()
                ->name('messages.reactions.store');

            Route::patch('c/{channel}/messages/{message}', MessageEditController::class)
                ->scopeBindings()
                ->name('messages.update');

            Route::delete('c/{channel}/messages/{message}', MessageDeletionController::class)
                ->scopeBindings()
                ->name('messages.destroy');

            /*
             * The only way to a shared file: the disk it sits on is private,
             * so there is no URL that works without this. Not scopeBindings():
             * media belongs to every model that has files, so the controller
             * checks by hand that this one is on this message.
             */
            Route::get('c/{channel}/messages/{message}/attachments/{media}', [MessageAttachmentController::class, 'show'])
                ->name('messages.attachments.show');
            Route::delete('c/{channel}/messages/{message}/attachments/{media}', [MessageAttachmentController::class, 'destroy'])
                ->name('messages.attachments.destroy');

            /*
             * Carrying a message into another conversation. Under the channel
             * it comes from, because that is the permission checked first —
             * the target is a field, and it is checked in the controller.
             */

            Route::middleware('feature:message-forwarding')->group(function () {
                Route::post('c/{channel}/messages/{message}/forward', MessageForwardController::class)
                    ->scopeBindings()
                    ->name('messages.forward');
            });

            /*
             * Setting a message aside for yourself. Next to pinning because the
             * gesture is the same shape, and deliberately not the same thing:
             * a pin is the channel's, this is one member's own.
             */

            Route::middleware('feature:saved-messages')->group(function () {
                Route::post('c/{channel}/messages/{message}/bookmark', [MessageBookmarkController::class, 'store'])
                    ->scopeBindings()
                    ->name('messages.bookmark');
                Route::delete('c/{channel}/messages/{message}/bookmark', [MessageBookmarkController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('messages.unbookmark');
            });

            // Pinning is one flag on the message, so there is nothing to
            // address beyond the message itself — POST puts it up, DELETE takes
            // it down.
            Route::post('c/{channel}/messages/{message}/pin', [MessagePinController::class, 'store'])
                ->scopeBindings()
                ->name('messages.pin');

            Route::delete('c/{channel}/messages/{message}/pin', [MessagePinController::class, 'destroy'])
                ->scopeBindings()
                ->name('messages.unpin');

            // Closing a thread only ever touches the signed-in member's own
            // view of it, so there is no id in the path beyond the thread.
            Route::post('c/{channel}/messages/{message}/close', [ThreadClosureController::class, 'store'])
                ->scopeBindings()
                ->name('threads.close');

            Route::delete('c/{channel}/messages/{message}/close', [ThreadClosureController::class, 'destroy'])
                ->scopeBindings()
                ->name('threads.reopen');
        });
});
