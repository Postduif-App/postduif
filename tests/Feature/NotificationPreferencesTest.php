<?php

use App\Actions\Chat\MarkChannelRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('has notifications switched off for a new account', function () {
    $user = User::factory()->create();

    expect($user->notify_after_minutes)->toBeNull()
        ->and($user->wantsAbsenceNotifications())->toBeFalse();
});

/**
 * A threshold with nowhere to deliver is a setting that would quietly produce
 * nothing, so both halves are the question.
 */
it('needs both a threshold and somewhere to deliver', function () {
    $user = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => false,
        'notify_via_pushover' => false,
    ]);

    expect($user->wantsAbsenceNotifications())->toBeFalse();

    $user->notify_via_mail = true;

    expect($user->wantsAbsenceNotifications())->toBeTrue();
});

it('does not count pushover as a delivery method without a key', function () {
    $user = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => false,
        'notify_via_pushover' => true,
    ]);

    expect($user->wantsPushover())->toBeFalse()
        ->and($user->wantsAbsenceNotifications())->toBeFalse();

    $user->pushover_user_key = 'u-sleutel-van-het-toestel';

    expect($user->wantsPushover())->toBeTrue()
        ->and($user->wantsAbsenceNotifications())->toBeTrue();
});

it('keeps the pushover key out of the table and out of payloads', function () {
    $user = User::factory()->create(['pushover_user_key' => 'u-sleutel-van-het-toestel']);

    $stored = DB::table('users')->where('id', $user->id)->value('pushover_user_key');

    expect($stored)->not->toBe('u-sleutel-van-het-toestel')
        ->and(Crypt::decryptString($stored))->toBe('u-sleutel-van-het-toestel')
        ->and($user->fresh()->pushover_user_key)->toBe('u-sleutel-van-het-toestel')
        ->and($user->fresh()->toArray())->not->toHaveKey('pushover_user_key');
});

it('stamps when a member last read a channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    expect($channel->members()->find($user->id)->pivot->last_read_at)->toBeNull();

    app(MarkChannelRead::class)->handle($channel, $user);

    expect($channel->members()->find($user->id)->pivot->last_read_at)->not->toBeNull();
});

/**
 * Opening a channel that holds nothing new is still the member being present in
 * it — which is exactly what the absence notifications ask about.
 */
it('stamps a visit even when there is nothing new to read', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    app(MarkChannelRead::class)->handle($channel, $user);

    $first = $channel->members()->find($user->id)->pivot->last_read_at;

    $this->travel(10)->minutes();

    app(MarkChannelRead::class)->handle($channel, $user);

    expect($channel->members()->find($user->id)->pivot->last_read_at)->not->toBe($first);
});
