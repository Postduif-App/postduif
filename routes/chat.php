<?php

use App\Http\Controllers\BoardCommentController;
use App\Http\Controllers\BoardPostController;
use App\Http\Controllers\BoardPostReactionController;
use App\Http\Controllers\BroadcastMessageController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelFavoriteController;
use App\Http\Controllers\ChannelFormController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ChannelLinkWorkflowController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChannelMuteController;
use App\Http\Controllers\ChannelSectionController;
use App\Http\Controllers\ChannelTagController;
use App\Http\Controllers\ChannelWebhookController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DirectMessageController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\DocumentRevisionController;
use App\Http\Controllers\EphemeralNoticeController;
use App\Http\Controllers\FormFillController;
use App\Http\Controllers\HuddleController;
use App\Http\Controllers\InviteLinkController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\MessageBookmarkController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageDeletionController;
use App\Http\Controllers\MessageEditController;
use App\Http\Controllers\MessageForwardController;
use App\Http\Controllers\MessagePinController;
use App\Http\Controllers\MessageWorkflowController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ScheduledMessageController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecretRequestController;
use App\Http\Controllers\SentSecretController;
use App\Http\Controllers\SlashCommandController;
use App\Http\Controllers\ThreadClosureController;
use App\Http\Controllers\ThreadMuteController;
use App\Http\Controllers\TicketCommentAttachmentController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TimeclockController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WorkspaceBookmarkController;
use App\Http\Controllers\WorkspaceCreationController;
use App\Http\Controllers\WorkspaceFormAnswerController;
use App\Http\Controllers\WorkspaceFormController;
use App\Http\Controllers\WorkspaceInboxController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Controllers\WorkspaceMemberProfileController;
use App\Http\Controllers\WorkspaceSecretController;
use App\Http\Controllers\WorkspaceTicketController;
use App\Http\Controllers\WorkspaceTransferController;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('app')->group(function () {
    Route::get('/', [ChatController::class, 'home'])->name('chat.home');

    /*
     * Making one of your own. Above the wildcard below and named in
     * Workspace::RESERVED_SLUGS, so neither the router nor a workspace can
     * claim the address.
     */
    Route::get('nieuw', [WorkspaceCreationController::class, 'create'])
        ->name('workspaces.create');
    Route::post('nieuw', [WorkspaceCreationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('workspaces.store');

    /**
     * The workspace slug is a wildcard directly under /app, so it could swallow
     * /app/settings. Two things keep that from happening: settings.php is
     * registered first, and the pattern below refuses the reserved names
     * outright — so a workspace can never claim one of those slugs either.
     */
    Route::prefix('{workspace}')
        ->name('chat.')
        ->where(['workspace' => '(?!(?:'.implode('|', Workspace::RESERVED_SLUGS).')$)[a-z0-9][a-z0-9-]*'])
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
             * Files put aside for somebody behind a link, and the same split as
             * above: making and withdrawing are things a member does from
             * inside, while following the link is for somebody who may have no
             * account at all — that half lives in web.php.
             */
            Route::middleware('feature:transfers')->group(function () {
                Route::post('transfers', [TransferController::class, 'store'])
                    ->name('transfers.store');
                Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])
                    ->name('transfers.destroy');

                // One address off the list, without disturbing the others. Its
                // own endpoint rather than a flag on the one above: withdrawing
                // the whole transfer and withdrawing one person's link are
                // different acts with different consequences.
                Route::delete('transfers/{transfer}/recipients/{recipient}', [TransferController::class, 'destroyRecipient'])
                    ->name('transfers.recipients.destroy');
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
             * A questionnaire, and the two things a member does with one from
             * inside the chat: put it in a channel, and fill it in.
             *
             * Writing the form is not here — that is a settings screen, because
             * a form outlives the conversation it is announced in. Filling one
             * in from outside is not here either: that door has a token instead
             * of an account and lives in web.php.
             */
            Route::middleware('feature:forms')->group(function () {
                /*
                 * Where a form is written. Beside the transfer and secret lists
                 * rather than under settings: it belongs to somebody's working
                 * day rather than to the configuration of the workspace, and it
                 * is opened from the same rail they were already looking at.
                 */
                Route::get('formulieren', [WorkspaceFormController::class, 'index'])
                    ->name('forms.index');
                Route::post('formulieren', [WorkspaceFormController::class, 'store'])
                    ->name('forms.store');

                /*
                 * The builder is a screen of its own rather than a panel in the
                 * list, for the reason the workflow builder is: writing a form
                 * takes more than one sitting and deserves an address somebody
                 * can bookmark and send to a colleague.
                 */
                Route::get('formulieren/{form}/bewerken', [WorkspaceFormController::class, 'edit'])
                    ->name('forms.edit');
                Route::put('formulieren/{form}', [WorkspaceFormController::class, 'update'])
                    ->name('forms.update');
                Route::delete('formulieren/{form}', [WorkspaceFormController::class, 'destroy'])
                    ->name('forms.destroy');

                /*
                 * Stopping it and starting it again. A POST and a DELETE on one
                 * address rather than two verbs of their own: "gesloten" is a
                 * thing a form either has or has not.
                 */
                Route::post('formulieren/{form}/gesloten', [WorkspaceFormController::class, 'close'])
                    ->name('forms.close');
                Route::delete('formulieren/{form}/gesloten', [WorkspaceFormController::class, 'reopen'])
                    ->name('forms.reopen');

                // The same shape for the public link, and the POST deliberately
                // replaces rather than being idempotent — see the controller.
                Route::post('formulieren/{form}/link', [WorkspaceFormController::class, 'share'])
                    ->name('forms.share');
                Route::delete('formulieren/{form}/link', [WorkspaceFormController::class, 'unshare'])
                    ->name('forms.unshare');

                Route::get('formulieren/{form}/antwoorden', [WorkspaceFormAnswerController::class, 'index'])
                    ->name('forms.answers');
                Route::get('formulieren/{form}/antwoorden/csv', [WorkspaceFormAnswerController::class, 'export'])
                    ->name('forms.answers.export');

                Route::post('c/{channel}/forms', [ChannelFormController::class, 'store'])
                    ->name('forms.post');

                /*
                 * The address that goes in the message, and so the shape
                 * PresentMessage matches on to know a link is a form. Short on
                 * purpose — it is pasted into conversations.
                 */
                Route::get('f/{form}', [FormFillController::class, 'show'])
                    ->name('forms.show');
                Route::post('f/{form}', [FormFillController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('forms.submit');
            });

            /*
             * The clock: in, out, and the week it recorded.
             *
             * In the chat rather than under settings, beside the forms it sits
             * under in the rail. Settings is where a workspace is configured
             * once and left alone; clocking in is the first thing somebody does
             * in the morning and the last thing at night, and sending them out
             * of the conversation to do it turns a daily act into
             * administration.
             *
             * The workspace is in the path, so the feature middleware guards
             * the whole group and a workspace with tijdregistratie switched off
             * has no such address at all. What is left for the controller is
             * the role — see WorkspacePolicy::clock, which keeps guests out.
             */
            Route::middleware('feature:timeclock')->group(function () {
                Route::get('tijdregistratie', [TimeclockController::class, 'index'])
                    ->name('timeclock.index');

                /*
                 * Pressed from the user menu, which is on every chat screen, so
                 * these are not part of the screen that lists the hours.
                 */
                Route::post('tijdregistratie/in', [TimeclockController::class, 'clockIn'])
                    ->name('timeclock.clock-in');
                Route::post('tijdregistratie/uit', [TimeclockController::class, 'clockOut'])
                    ->name('timeclock.clock-out');

                Route::patch('tijdregistratie/voorkeur', [TimeclockController::class, 'updatePreference'])
                    ->name('timeclock.preference');

                /*
                 * A stretch the clock never saw, typed in afterwards. Its own
                 * literal segment rather than the collection address, because
                 * the two above already spend POST on the buttons — and
                 * "periode" is what the screen calls the thing being added.
                 */
                Route::post('tijdregistratie/periode', [TimeEntryController::class, 'store'])
                    ->name('timeclock.entries.store');

                /*
                 * Correcting what was recorded. Judged by TimeEntryPolicy,
                 * which only ever says yes to the person the stretch is about —
                 * so an id from a colleague's week is refused however senior
                 * the asker is.
                 */
                Route::patch('tijdregistratie/{timeEntry}', [TimeEntryController::class, 'update'])
                    ->whereNumber('timeEntry')
                    ->name('timeclock.entries.update');
                Route::delete('tijdregistratie/{timeEntry}', [TimeEntryController::class, 'destroy'])
                    ->whereNumber('timeEntry')
                    ->name('timeclock.entries.destroy');
            });

            /*
             * A PDF sent out to be signed. The same split as the transfers
             * above: uploading it, drawing the boxes and following what came
             * back are things a member does from inside, while filling the
             * thing in is for somebody who may have no account at all — that
             * half lives in web.php.
             */
            Route::middleware('feature:contracts')->group(function () {
                /*
                 * The list, beside the transfer and form lists rather than
                 * under settings: sending a contract belongs to somebody's
                 * working day, not to the configuration of the workspace.
                 */
                Route::get('contracten', [ContractController::class, 'index'])
                    ->name('contracts.index');

                Route::post('contracts', [ContractController::class, 'store'])
                    ->name('contracts.store');

                /*
                 * Where the boxes are drawn. A screen of its own rather than a
                 * panel, for the reason the form builder has one: laying out a
                 * contract is done with the document in front of you at a
                 * readable size, and it deserves an address somebody can come
                 * back to.
                 */
                /*
                 * Where a contract card in a channel leads. One address for
                 * everybody, and the controller decides per viewer — see there
                 * for why it cannot be decided when the card is drawn.
                 */
                Route::get('contracten/{contract}', [ContractController::class, 'show'])
                    ->name('contracts.show');

                Route::get('contracten/{contract}/bewerken', [ContractController::class, 'edit'])
                    ->name('contracts.edit');
                Route::put('contracten/{contract}/velden', [ContractController::class, 'updateFields'])
                    ->name('contracts.fields');

                /*
                 * Naming the people and putting it in the post. A POST rather
                 * than a flag on the save above: laying out the boxes is a
                 * thing you do half a dozen times, and handing the document to
                 * the outside world is a thing you do once.
                 *
                 * Throttled, unlike the rest of this group, because this one
                 * sends mail to addresses somebody typed — the only endpoint in
                 * the feature that can reach a stranger's inbox.
                 */
                Route::post('contracten/{contract}/versturen', [ContractController::class, 'send'])
                    ->middleware('throttle:10,1')
                    ->name('contracts.send');

                Route::post('contracten/{contract}/herinnering', [ContractController::class, 'remind'])
                    ->middleware('throttle:10,1')
                    ->name('contracts.remind');

                /*
                 * Stopping it. A DELETE on the contract would read as throwing
                 * it away, which is a different act with a different policy —
                 * see ContractPolicy, where cancel outlives delete.
                 */
                Route::post('contracten/{contract}/intrekken', [ContractController::class, 'cancel'])
                    ->name('contracts.cancel');

                // Telling the colleagues, which is not the same as asking the
                // signers — see PostContractToChannel.
                Route::post('contracten/{contract}/plaatsen', [ContractController::class, 'post'])
                    ->name('contracts.post');

                Route::post('contracten/{contract}/opnieuw', [ContractController::class, 'retryRender'])
                    ->middleware('throttle:6,1')
                    ->name('contracts.retry');

                /*
                 * The document itself, for the editor and the signing page to
                 * render. Behind the policy, because there is no other way to
                 * the private disk and there should not be — see the controller.
                 */
                Route::get('contracts/{contract}/source', [ContractController::class, 'source'])
                    ->name('contracts.source');

                /*
                 * The finished article. Apart from the source above because
                 * the two are different documents: one is what was sent, the
                 * other is the record of what happened to it.
                 */
                Route::get('contracten/{contract}/ondertekend', [ContractController::class, 'download'])
                    ->name('contracts.download');
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

                /*
                 * The other direction: handing one over rather than asking for
                 * one. Under the same feature switch, because a workspace that
                 * has decided not to route credentials through this application
                 * has decided it for both directions.
                 *
                 * Only the making and the withdrawing live here. Picking one up
                 * is in web.php, outside auth — the key is in the link and the
                 * recipient may have no account at all.
                 */
                Route::post('c/{channel}/geheimen', [SentSecretController::class, 'store'])
                    ->name('sent-secrets.store');

                /*
                 * The same thing without a room to announce it in: everything
                 * this member has put aside, and the place to put aside one
                 * more. Beside the ticket and transfer lists rather than under
                 * a channel, and for the same reason — it belongs to a person's
                 * working day rather than to any one conversation.
                 */
                Route::get('geheimen', [WorkspaceSecretController::class, 'index'])
                    ->name('sent-secrets.index');
                Route::post('geheimen', [WorkspaceSecretController::class, 'store'])
                    ->name('sent-secrets.store-standalone');
                Route::delete('geheimen/{sentSecret}', [SentSecretController::class, 'destroy'])
                    ->name('sent-secrets.destroy');
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

            /*
             * Stopping one before it goes out. No channel in the path, because
             * a scheduled broadcast belongs to none — that is the whole reason
             * it is not a scheduled message.
             */
            Route::delete('broadcast/{scheduledBroadcast}', [BroadcastMessageController::class, 'destroy'])
                ->name('broadcast.destroy');

            /**
             * Clears the conversation out of your own sidebar and nobody
             * else's. Delete because that is the gesture, not because anything
             * is removed — see HideDirectMessage.
             */
            Route::delete('dm/{channel}', [DirectMessageController::class, 'destroy'])
                ->name('directs.destroy');

            /*
             * Het prikbord: mededelingen voor de hele workspace.
             *
             * Not under c/{channel} for a stronger reason than the ticket list
             * below it. Those are gathered from channels and only look
             * workspace-wide; a notice has no channel at all — it is the
             * workspace addressing the people in it, which is exactly why a
             * guest never sees this whole group. BoardPostPolicy is what says
             * so, on every route here.
             *
             * The list is read at ?post= rather than on a path of its own, the
             * same way an open thread and an open ticket travel: the board stays
             * beside what is being read, and the URL carries both.
             */
            Route::middleware('feature:message-board')->group(function () {
                Route::get('prikbord', [BoardPostController::class, 'index'])
                    ->name('board.index');
                Route::post('prikbord', [BoardPostController::class, 'store'])
                    ->name('board.store');

                /*
                 * Scoped, so a notice from another workspace is a 404 before any
                 * controller runs rather than a check each method has to
                 * remember to make.
                 */
                Route::patch('prikbord/{board_post}', [BoardPostController::class, 'update'])
                    ->scopeBindings()
                    ->name('board.update');
                Route::delete('prikbord/{board_post}', [BoardPostController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('board.destroy');

                /*
                 * Eén pad voor beide richtingen, zoals bij berichten: de
                 * browser hoeft nooit te weten of hij een emoji toevoegt of
                 * weghaalt, dus een pagina van een minuut oud kan het ook niet
                 * verkeerd hebben.
                 */
                Route::post('prikbord/{board_post}/emoji', [BoardPostReactionController::class, 'store'])
                    ->scopeBindings()
                    ->name('board.reactions.store');

                Route::post('prikbord/{board_post}/reacties', [BoardCommentController::class, 'store'])
                    ->scopeBindings()
                    ->name('board.comments.store');
                Route::patch('prikbord/{board_post}/reacties/{comment}', [BoardCommentController::class, 'update'])
                    ->scopeBindings()
                    ->name('board.comments.update');
                Route::delete('prikbord/{board_post}/reacties/{comment}', [BoardCommentController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('board.comments.destroy');
            });

            /**
             * Every ticket in the workspace, across the channels this member
             * can see. Not under c/{channel}: it belongs to no channel, which
             * is the whole point of it.
             */
            Route::get('tickets', [WorkspaceTicketController::class, 'index'])
                ->middleware('feature:tickets')
                ->name('tickets.index');

            /*
             * Everything sent by link, across the workspace. Beside the tickets
             * list rather than under settings, and for the same reason it is
             * not under c/{channel}: it belongs to a person's working day, not
             * to one conversation and not to administration.
             */
            /*
             * Who somebody is. Under the workspace and not under a channel:
             * a person belongs to the workspace, and reaching their page from
             * a channel they happen to have written in would make the address
             * depend on where you clicked.
             */
            Route::get('leden/{member}', [WorkspaceMemberProfileController::class, 'show'])
                ->name('members.show');

            Route::get('transfers', [WorkspaceTransferController::class, 'index'])
                ->middleware('feature:transfers')
                ->name('transfers.index');
            /*
             * Every place this member was named. Not under c/{channel} for the
             * same reason the ticket list is not: being named is something that
             * happens to a person across channels, not inside one.
             */
            Route::get('inbox', [WorkspaceInboxController::class, 'index'])
                ->name('inbox.index');

            /*
             * Opening a row: mark it off, then go where it points.
             *
             * A GET that writes, which is normally the wrong shape — but the
             * write is idempotent and the alternative is worse. A POST would
             * have to be followed by the navigation, and Inertia cancels an
             * in-flight visit when a new one starts, so the two would race.
             * More plainly: this has to stay a link. Middle-click, open in a
             * new tab and the address on hover are how a list of things to
             * read gets used, and a button has none of them.
             */
            Route::get('inbox/{item}/openen', [WorkspaceInboxController::class, 'open'])
                ->name('inbox.open');

            /*
             * The inbox with the mentions tab already chosen. Kept as its own
             * path because it is where the sidebar badge has always pointed and
             * where somebody's bookmarks still go — the page moved, the address
             * did not have to.
             */
            Route::get('mentions', [WorkspaceInboxController::class, 'mentions'])
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
            Route::patch('sections/{section}', [ChannelSectionController::class, 'rename'])
                ->name('sections.rename');
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

            /*
             * Talking in a channel. Behind the feature flag because it needs a
             * relay server arranged before it works for everybody — see the
             * Huddles feature.
             */
            Route::middleware('feature:huddles')->group(function () {
                Route::post('c/{channel}/huddle', [HuddleController::class, 'store'])
                    ->name('huddles.store');
                /*
                 * "Ik ben er nog", every half minute from every browser in a
                 * huddle. Not throttled with the others: this is the signal a
                 * huddle uses to notice that somebody's browser is gone, and
                 * refusing it would be manufacturing the very silence the
                 * sweeper reads as death.
                 */
                Route::patch('c/{channel}/huddle/{huddle}', [HuddleController::class, 'update'])
                    ->scopeBindings()
                    ->name('huddles.ping');

                Route::delete('c/{channel}/huddle/{huddle}', [HuddleController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('huddles.destroy');
            });

            /*
             * Typing a workflow's command in the message field. Throttled like
             * the button: a command that seems to do nothing gets typed again.
             */
            Route::post('c/{channel}/commands', [SlashCommandController::class, 'store'])
                ->middleware(['feature:workflows', 'throttle:20,1'])
                ->name('commands.store');

            /*
             * Throwing away something you alone were told. No store beside it:
             * a notice is written by whatever it is a receipt for — a command,
             * a button — and never by somebody asking for one.
             */
            Route::delete('c/{channel}/notices/{notice}', [EphemeralNoticeController::class, 'destroy'])
                ->scopeBindings()
                ->name('notices.destroy');

            /*
             * Pressing one of those buttons, which is the one thing here an
             * ordinary member may do rather than whoever manages the channel.
             * Throttled because a button is a thing people press again when
             * nothing visibly happens, and every press is a workflow run.
             */
            Route::post('c/{channel}/links/{link}/run', [ChannelLinkWorkflowController::class, 'store'])
                ->scopeBindings()
                ->middleware(['feature:workflows', 'throttle:20,1'])
                ->name('channels.links.run');

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

                Route::delete('c/{channel}/tickets/{ticket}', [TicketController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('tickets.destroy');

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
             * Documents are read through chat.show with ?view=document, the same
             * arrangement the ticket board has. Only the writes live here.
             */
            Route::middleware('feature:documents')->group(function () {
                Route::post('c/{channel}/documents', [DocumentController::class, 'store'])
                    ->name('documents.store');

                /*
                 * Scoped, so a document number from another channel is a 404
                 * rather than somebody else's document opened by guessing.
                 */
                Route::patch('c/{channel}/documents/{document}', [DocumentController::class, 'update'])
                    ->scopeBindings()
                    ->name('documents.update');

                Route::delete('c/{channel}/documents/{document}', [DocumentController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('documents.destroy');

                /*
                 * The files inside a document. Scoped the whole way down —
                 * channel to document to file — so an id from somewhere else is
                 * a 404 during binding rather than something the controller has
                 * to notice. Unlike the media a message carries, these hang off
                 * a relation Laravel can follow.
                 */
                Route::post('c/{channel}/documents/{document}/files', [DocumentFileController::class, 'store'])
                    ->scopeBindings()
                    ->name('documents.files.store');

                Route::delete('c/{channel}/documents/{document}/files/{file}', [DocumentFileController::class, 'destroy'])
                    ->scopeBindings()
                    ->name('documents.files.destroy');

                /*
                 * What the document said before. Behind the feature gate with
                 * the writes rather than with the reading of files: a history
                 * is only reachable from the editor, and the editor is what the
                 * gate turns off.
                 */
                Route::get('c/{channel}/documents/{document}/geschiedenis', [DocumentRevisionController::class, 'index'])
                    ->scopeBindings()
                    ->name('documents.revisions.index');

                Route::get('c/{channel}/documents/{document}/geschiedenis/{revision}', [DocumentRevisionController::class, 'show'])
                    ->scopeBindings()
                    ->name('documents.revisions.show');

                Route::post('c/{channel}/documents/{document}/geschiedenis/{revision}', [DocumentRevisionController::class, 'restore'])
                    ->scopeBindings()
                    ->name('documents.revisions.restore');
            });

            /*
             * Reading a file out of a document, and the one document route that
             * sits outside the feature gate.
             *
             * Switching the feature off hides the editor and stops the writing;
             * it should not turn every picture in every document that already
             * exists into a broken box for as long as somebody has the page
             * open. The policy still decides who gets in.
             */
            Route::get('c/{channel}/documents/{document}/files/{file}', [DocumentFileController::class, 'show'])
                ->scopeBindings()
                ->name('documents.files.show');

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

            /*
             * Setting a workflow off by hand, from the message menu. Behind the
             * feature middleware rather than only checked in the controller, so
             * a workspace with workflows switched off has no such URL at all.
             */
            /*
             * Without scopeBindings, unlike its neighbours. The chain would
             * make {workflow} a child of {message}, and a workflow hangs off a
             * workspace rather than off a message — the controller checks both
             * belong where they should, by hand and in one place.
             */
            Route::post('c/{channel}/messages/{message}/workflows/{workflow}', [MessageWorkflowController::class, 'store'])
                ->middleware('feature:workflows')
                ->name('messages.workflows.start');

            /*
             * All four of these accept a deleted parent, which is why they say
             * withTrashed(). A thread whose opening message was deleted keeps
             * its replies and stays in the sidebar as a tombstone — see
             * Message::scopeVisible — so the very buttons drawn beside it must
             * resolve it, and implicit binding hides trashed rows unless told
             * otherwise. Only {message} is affected: neither channels nor
             * workspaces are soft-deleted.
             */

            // Closing a thread only ever touches the signed-in member's own
            // view of it, so there is no id in the path beyond the thread.
            Route::post('c/{channel}/messages/{message}/close', [ThreadClosureController::class, 'store'])
                ->scopeBindings()
                ->withTrashed()
                ->name('threads.close');

            Route::delete('c/{channel}/messages/{message}/close', [ThreadClosureController::class, 'destroy'])
                ->scopeBindings()
                ->withTrashed()
                ->name('threads.reopen');

            /*
             * Muting sits beside closing rather than replacing it: closing is
             * about the sidebar and is undone by the next reply, muting is
             * about the inbox and is not undone by anything but the delete
             * below it.
             */
            Route::post('c/{channel}/messages/{message}/mute', [ThreadMuteController::class, 'store'])
                ->scopeBindings()
                ->withTrashed()
                ->name('threads.mute');

            Route::delete('c/{channel}/messages/{message}/mute', [ThreadMuteController::class, 'destroy'])
                ->scopeBindings()
                ->withTrashed()
                ->name('threads.unmute');
        });
});
