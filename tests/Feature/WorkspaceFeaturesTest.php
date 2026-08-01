<?php

use App\Features\AiAccess;
use App\Features\Tickets;
use App\Features\WorkspaceFeature;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

it('offers a new workspace everything except a way in from outside', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->hasFeature(Tickets::class))->toBeTrue()
        // Handing the conversation to an AI client is a decision, not a default.
        ->and($workspace->hasFeature(AiAccess::class))->toBeFalse();
});

it('remembers what a workspace switched off', function () {
    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->deactivate(Tickets::class);

    expect($workspace->hasFeature(Tickets::class))->toBeFalse();
});

/** Two workspaces, one flag: the answer must not carry from one to the other. */
it('answers per workspace', function () {
    $off = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    Feature::for($off)->deactivate(Tickets::class);

    expect($off->hasFeature(Tickets::class))->toBeFalse()
        ->and($other->hasFeature(Tickets::class))->toBeTrue();
});

it('lists every feature with its stand', function () {
    $workspace = Workspace::factory()->create();

    Feature::for($workspace)->activate(AiAccess::class);

    $states = $workspace->featureStates();

    expect(array_keys($states))->toBe(WorkspaceFeature::ALL)
        ->and($states[AiAccess::class])->toBeTrue()
        ->and($states[Tickets::class])->toBeTrue();
});

it('gives every feature a name and a sentence for the beheerscherm', function () {
    foreach (WorkspaceFeature::ALL as $feature) {
        expect($feature::label())->not->toBeEmpty()
            ->and($feature::description())->not->toBeEmpty();
    }
});
