<?php

use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\McpTokenController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\StatusController;
use App\Http\Controllers\Settings\WorkspaceController;
use App\Http\Controllers\Settings\WorkspaceInvitationController;
use App\Http\Controllers\Settings\WorkspaceMemberController;
use App\Http\Controllers\Settings\WorkspacePermissionController;
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
    Route::post('app/settings/avatar', [AvatarController::class, 'store'])->name('avatar.store');
    Route::delete('app/settings/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

    Route::get('app/settings/mcp-tokens', [McpTokenController::class, 'index'])
        ->name('mcp-tokens.index');
    Route::post('app/settings/mcp-tokens', [McpTokenController::class, 'store'])
        ->name('mcp-tokens.store');
    Route::delete('app/settings/mcp-tokens/{mcpToken}', [McpTokenController::class, 'destroy'])
        ->name('mcp-tokens.destroy');

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

    Route::get('app/settings/workspace/theme', [WorkspaceThemeController::class, 'edit'])
        ->name('workspace.theme.edit');
    Route::patch('app/settings/workspace/theme', [WorkspaceThemeController::class, 'update'])
        ->name('workspace.theme.update');

    Route::get('app/settings/workspace/members', [WorkspaceMemberController::class, 'index'])
        ->name('workspace.members.index');
    Route::patch('app/settings/workspace/members/{user}', [WorkspaceMemberController::class, 'update'])
        ->name('workspace.members.update');
    Route::put('app/settings/workspace/members/{user}/channels', [WorkspaceMemberController::class, 'updateChannels'])
        ->name('workspace.members.channels.update');
    Route::delete('app/settings/workspace/members/{user}', [WorkspaceMemberController::class, 'destroy'])
        ->name('workspace.members.destroy');

    Route::get('app/settings/workspace/invitations', [WorkspaceInvitationController::class, 'index'])
        ->name('workspace.invitations.index');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
