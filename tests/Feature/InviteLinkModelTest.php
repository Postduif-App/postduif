<?php

use App\Models\InviteLink;
use App\Models\Workspace;

/**
 * Built rather than saved: the three reasons a link stops working are decided
 * from its own attributes, so none of this needs a database behind it.
 *
 * @param  array<string, mixed>  $attributes
 */
function inviteLink(array $attributes = []): InviteLink
{
    return (new InviteLink)->forceFill([
        'max_uses' => null,
        'expires_at' => null,
        'uses' => 0,
        'revoked_at' => null,
        ...$attributes,
    ]);
}

it('lets a fresh link through', function () {
    expect(inviteLink()->isUsable())->toBeTrue();
});

it('treats a link with no date and no maximum as unbounded', function () {
    $unbounded = inviteLink(['uses' => 9999]);

    expect($unbounded->hasExpired())->toBeFalse()
        ->and($unbounded->isExhausted())->toBeFalse()
        ->and($unbounded->isUsable())->toBeTrue();
});

it('stops at the maximum', function () {
    expect(inviteLink(['max_uses' => 3, 'uses' => 2])->isExhausted())->toBeFalse()
        ->and(inviteLink(['max_uses' => 3, 'uses' => 3])->isExhausted())->toBeTrue()
        ->and(inviteLink(['max_uses' => 3, 'uses' => 3])->isUsable())->toBeFalse();
});

it('stops after its date', function () {
    expect(inviteLink(['expires_at' => now()->addHour()])->hasExpired())->toBeFalse()
        ->and(inviteLink(['expires_at' => now()->subSecond()])->hasExpired())->toBeTrue()
        ->and(inviteLink(['expires_at' => now()->subSecond()])->isUsable())->toBeFalse();
});

it('stops once it is withdrawn, whatever else it had left', function () {
    $withdrawn = inviteLink(['revoked_at' => now()->subHour(), 'max_uses' => 100]);

    expect($withdrawn->isRevoked())->toBeTrue()
        ->and($withdrawn->isUsable())->toBeFalse();
});

it('finds the same links in SQL as isUsable does in PHP', function () {
    $workspace = Workspace::factory()->create();
    $usable = InviteLink::factory()->for($workspace)->unlimited()->create();

    InviteLink::factory()->for($workspace)->expired()->create();
    InviteLink::factory()->for($workspace)->revoked()->create();
    InviteLink::factory()->for($workspace)->exhausted()->create();

    expect(InviteLink::usable()->pluck('id')->all())->toBe([$usable->id])
        ->and(InviteLink::all()->filter->isUsable()->pluck('id')->all())
        ->toBe([$usable->id]);
});

it('keeps the token out of anything serialised', function () {
    $link = (new InviteLink)->forceFill(['token' => 'secret', 'uses' => 0]);

    expect($link->toArray())->not->toHaveKey('token')
        ->and($link->token)->toBe('secret');
});
