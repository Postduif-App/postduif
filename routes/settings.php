<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\CustomEmojiController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\StatusController;
use App\Http\Controllers\Settings\StatusRuleController;
use App\Http\Controllers\Settings\WorkflowController;
use App\Http\Controllers\Settings\WorkflowRunController;
use App\Http\Controllers\Settings\WorkspaceChannelController;
use App\Http\Controllers\Settings\WorkspaceController;
use App\Http\Controllers\Settings\WorkspaceInvitationController;
use App\Http\Controllers\Settings\WorkspaceMailController;
use App\Http\Controllers\Settings\WorkspaceMemberController;
use App\Http\Controllers\Settings\WorkspacePermissionController;
use App\Http\Controllers\Settings\WorkspaceRoleController;
use App\Http\Controllers\Settings\WorkspaceThemeController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('app/settings', '/app/settings/profile');

    Route::get('app/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('app/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('app/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('app/settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('app/settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('app/settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Not under /settings: this is set from the user menu, not from a screen.
    Route::patch('app/status', [StatusController::class, 'update'])->name('status.update');

    /*
     * The tokens a member handed to an AI client. Their own screen: a token
     * acts as the person across every workspace they belong to.
     */
    /*
     * Your own face. Only ever your own — an avatar is how somebody chooses to
     * appear, and nobody else gets to choose it for them.
     */
    Route::post('app/settings/workspace/avatar', [WorkspaceController::class, 'storeAvatar'])
        ->name('workspace.avatar.store');
    Route::delete('app/settings/workspace/avatar', [WorkspaceController::class, 'destroyAvatar'])
        ->name('workspace.avatar.destroy');

    Route::post('app/settings/avatar', [AvatarController::class, 'store'])->name('avatar.store');
    Route::delete('app/settings/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

    /*
     * The rules that set somebody's status for them. Their own screen and
     * nobody else's: a status is what a person says about themselves.
     */
    Route::get('app/settings/status-rules', [StatusRuleController::class, 'index'])
        ->name('status-rules.index');
    Route::post('app/settings/status-rules', [StatusRuleController::class, 'store'])
        ->name('status-rules.store');
    Route::put('app/settings/status-rules/order', [StatusRuleController::class, 'reorder'])
        ->name('status-rules.reorder');
    Route::patch('app/settings/status-rules/{statusRule}', [StatusRuleController::class, 'update'])
        ->name('status-rules.update');
    Route::delete('app/settings/status-rules/{statusRule}', [StatusRuleController::class, 'destroy'])
        ->name('status-rules.destroy');

    Route::get('app/settings/api-tokens', [ApiTokenController::class, 'index'])
        ->name('api-tokens.index');
    Route::post('app/settings/api-tokens', [ApiTokenController::class, 'store'])
        ->name('api-tokens.store');
    Route::delete('app/settings/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])
        ->name('api-tokens.destroy');

    Route::get('app/settings/notifications', [NotificationController::class, 'edit'])
        ->name('notifications.edit');
    Route::patch('app/settings/notifications', [NotificationController::class, 'update'])
        ->name('notifications.update');

    Route::get('app/settings/workspace', [WorkspaceController::class, 'edit'])->name('workspace.edit');
    Route::patch('app/settings/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');

    Route::get('app/settings/workspace/permissions', [WorkspacePermissionController::class, 'edit'])
        ->name('workspace.permissions.edit');
    Route::patch('app/settings/workspace/permissions', [WorkspacePermissionController::class, 'update'])
        ->name('workspace.permissions.update');

    /*
     * The roles a workspace writes for itself. Its own screen rather than a
     * block on the permissions page: naming a role and deciding what it may do
     * is a thing you sit down to, and the page it would have shared is a list
     * of switches you flick on the way past.
     */
    Route::get('app/settings/workspace/roles', [WorkspaceRoleController::class, 'index'])
        ->name('workspace.roles.index');
    Route::post('app/settings/workspace/roles', [WorkspaceRoleController::class, 'store'])
        ->name('workspace.roles.store');
    Route::patch('app/settings/workspace/roles/{role}', [WorkspaceRoleController::class, 'update'])
        ->name('workspace.roles.update');
    Route::delete('app/settings/workspace/roles/{role}', [WorkspaceRoleController::class, 'destroy'])
        ->name('workspace.roles.destroy');

    /*
     * The pictures a workspace names for itself. Its own screen rather than a
     * field on the general page: it is a list that grows, and every row of it
     * is a file somebody uploaded.
     */
    Route::get('app/settings/workspace/emoji', [CustomEmojiController::class, 'index'])
        ->name('workspace.emoji.index');
    Route::post('app/settings/workspace/emoji', [CustomEmojiController::class, 'store'])
        ->name('workspace.emoji.store');
    Route::delete('app/settings/workspace/emoji/{customEmoji}', [CustomEmojiController::class, 'destroy'])
        ->name('workspace.emoji.destroy');

    Route::get('app/settings/workspace/theme', [WorkspaceThemeController::class, 'edit'])
        ->name('workspace.theme.edit');
    Route::patch('app/settings/workspace/theme', [WorkspaceThemeController::class, 'update'])
        ->name('workspace.theme.update');

    /*
     * Where the workspace's mail leaves from. The test send is throttled and
     * the other two are not: saving a form is somebody's own business, but a
     * button that makes a third party send a message is one a script could
     * lean on — and every press costs the workspace credit at their provider.
     */
    Route::get('app/settings/workspace/mail', [WorkspaceMailController::class, 'edit'])
        ->name('workspace.mail.edit');
    Route::patch('app/settings/workspace/mail', [WorkspaceMailController::class, 'update'])
        ->name('workspace.mail.update');
    Route::post('app/settings/workspace/mail/test', [WorkspaceMailController::class, 'test'])
        ->middleware('throttle:6,1')
        ->name('workspace.mail.test');

    /*
     * No workspace in the path, like every other settings route — the current
     * one is worked out from the member. That also rules out the feature
     * middleware, which reads {workspace} off the route; here the gate is
     * WorkspacePolicy::manageWorkflows, which asks about the feature and the
     * role in one breath.
     */
    Route::get('app/settings/workflows', [WorkflowController::class, 'index'])
        ->name('workflows.index');
    Route::post('app/settings/workflows', [WorkflowController::class, 'store'])
        ->name('workflows.store');
    /*
     * The builder is a screen of its own rather than a panel that folds open in
     * the list. A workflow being written is a piece of work with a beginning and
     * an end — one that takes several sittings — and it deserves an address
     * somebody can bookmark, reload and send to a colleague.
     */
    Route::get('app/settings/workflows/{workflow}/edit', [WorkflowController::class, 'edit'])
        ->name('workflows.edit');
    Route::put('app/settings/workflows/{workflow}', [WorkflowController::class, 'update'])
        ->name('workflows.update');
    Route::patch('app/settings/workflows/{workflow}/enabled', [WorkflowController::class, 'toggle'])
        ->name('workflows.toggle');
    Route::delete('app/settings/workflows/{workflow}', [WorkflowController::class, 'destroy'])
        ->name('workflows.destroy');

    /*
     * The face its messages carry. Beside the workspace's own logo endpoints
     * and shaped the same way: a POST that replaces whatever was there, and a
     * DELETE that puts it back to the default bot mark.
     */
    Route::post('app/settings/workflows/{workflow}/avatar', [WorkflowController::class, 'storeAvatar'])
        ->name('workflows.avatar.store');
    Route::delete('app/settings/workflows/{workflow}/avatar', [WorkflowController::class, 'destroyAvatar'])
        ->name('workflows.avatar.destroy');
    Route::get('app/settings/workflows/{workflow}/runs', [WorkflowRunController::class, 'index'])
        ->name('workflows.runs');

    Route::get('app/settings/workspace/members', [WorkspaceMemberController::class, 'index'])
        ->name('workspace.members.index');
    Route::patch('app/settings/workspace/members/{user}', [WorkspaceMemberController::class, 'update'])
        ->name('workspace.members.update');
    Route::put('app/settings/workspace/members/{user}/channels', [WorkspaceMemberController::class, 'updateChannels'])
        ->name('workspace.members.channels.update');
    Route::delete('app/settings/workspace/members/{user}', [WorkspaceMemberController::class, 'destroy'])
        ->name('workspace.members.destroy');

    /*
     * Read-only, and on purpose: what a channel is called and who is in it is
     * changed where the channel is, not from a list. What this screen adds is
     * the overview — including the archived ones, which are invisible anywhere
     * else — and the one action that cannot be reached once a channel is
     * archived, namely bringing it back.
     */
    Route::get('app/settings/workspace/channels', [WorkspaceChannelController::class, 'index'])
        ->name('workspace.channels.index');

    Route::get('app/settings/workspace/invitations', [WorkspaceInvitationController::class, 'index'])
        ->name('workspace.invitations.index');

    /*
     * What somebody has standing out there behind a download link. Not gated by
     * the feature middleware, which needs a workspace in the route and there is
     * none here — the controller asks the same question and answers with the
     * same 404.
     */
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
