<?php

use App\Actions\Users\ApplyStatusRules;
use App\Enums\Availability;
use App\Features\AiAccess;
use App\Mcp\Servers\ChatServer;
use App\Mcp\Tools\SetStatusTool;
use App\Models\StatusRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/** A member whose workspace has let AI clients in. */
function statusSetter(): User
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $workspace = workspaceWithMember($user);

    Feature::for($workspace)->activate(AiAccess::class);

    return $user;
}

it('sets the status', function () {
    $user = statusSetter();

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'In vergadering', 'emoji' => '📅'])
        ->assertOk();

    $user->refresh();

    expect($user->status_text)->toBe('In vergadering')
        ->and($user->status_emoji)->toBe('📅');
});

it('takes the status away again when the text is empty', function () {
    $user = statusSetter();
    $user->forceFill(['status_text' => 'Oud', 'status_emoji' => '☕'])->save();

    ChatServer::actingAs($user)->tool(SetStatusTool::class, ['text' => ''])->assertOk();

    expect($user->refresh()->status_text)->toBeNull();
});

/**
 * Being away is a separate question from having a status, so saying one must
 * not quietly answer the other.
 */
it('leaves the availability alone when nothing is said about it', function () {
    $user = statusSetter();
    $user->forceFill(['availability' => Availability::DoNotDisturb])->save();

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'Aan het lezen'])
        ->assertOk();

    expect($user->refresh()->availability)->toBe(Availability::DoNotDisturb);
});

it('changes the availability when asked to', function () {
    $user = statusSetter();

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'Even weg', 'availability' => 'away'])
        ->assertOk();

    expect($user->refresh()->availability)->toBe(Availability::Away);
});

/**
 * A status is one person's, but everybody sharing a channel with them sees it.
 * Where no workspace has let AI clients in, changing how somebody appears to
 * their colleagues would be going around the switch that says no.
 */
it('refuses where no workspace has let an AI client in', function () {
    $user = User::factory()->create();
    workspaceWithMember($user);

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'Toch iets'])
        ->assertHasErrors();

    expect($user->refresh()->status_text)->toBeNull();
});

/**
 * A status set here is in every way one the member set: they asked for it. So
 * it wins over their schedule until that window ends, and not a minute longer.
 */
it('counts as the member speaking, where a schedule is running', function () {
    $user = statusSetter();

    StatusRule::factory()->workdays()->for($user)->create([
        'status_text' => 'Aan het werk',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-08-03 10:00', 'Europe/Amsterdam'));
    app(ApplyStatusRules::class)->handle();

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'Even bellen'])
        ->assertOk();

    // Still inside the working window, so the schedule leaves it be.
    Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'Europe/Amsterdam'));
    app(ApplyStatusRules::class)->handle();

    expect($user->refresh()->status_text)->toBe('Even bellen');

    // And once the window is over, the schedule takes back over.
    Carbon::setTestNow(Carbon::parse('2026-08-03 18:00', 'Europe/Amsterdam'));
    app(ApplyStatusRules::class)->handle();
    Carbon::setTestNow();

    expect($user->refresh()->status_text)->toBeNull();
});

it('offers the status back as a shortcut, the way the picker does', function () {
    $user = statusSetter();

    ChatServer::actingAs($user)
        ->tool(SetStatusTool::class, ['text' => 'Koffie', 'emoji' => '☕'])
        ->assertOk();

    expect($user->refresh()->recent_statuses)
        ->toEqual([['emoji' => '☕', 'text' => 'Koffie']]);
});
