<?php

use App\Enums\AttachmentType;
use App\Models\Workspace;

/**
 * The migration that lets existing workspaces record a voice note.
 *
 * Run by hand here rather than relied on through RefreshDatabase: every
 * workspace in a test is created after it, so the interesting cases — a list
 * that was never touched, and one that was — have to be built and then put
 * through it.
 */
function runAudioBackfill(): void
{
    $migration = require database_path('migrations/2026_08_01_181915_allow_audio_in_untouched_workspaces.php');

    $migration->up();
}

it('adds audio to a workspace that never touched the setting', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => ['images', 'video', 'documents'],
    ]);

    runAudioBackfill();

    expect($workspace->fresh()->allowed_attachment_types)
        ->toBe(AttachmentType::defaults());
});

/** The order jsonb hands a list back in is not a decision anybody made. */
it('recognises the original list whatever order it comes back in', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => ['documents', 'images', 'video'],
    ]);

    runAudioBackfill();

    expect($workspace->fresh()->allowed_attachment_types)->toContain('audio');
});

/**
 * Somebody who deliberately unticked something should not find it back after a
 * deploy — which is the whole reason this migration is narrow.
 */
it('leaves a workspace that made its own choice alone', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => ['images'],
    ]);

    runAudioBackfill();

    expect($workspace->fresh()->allowed_attachment_types)->toBe(['images']);
});

it('leaves a workspace that already allows audio alone', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => ['images', 'audio'],
    ]);

    runAudioBackfill();

    expect($workspace->fresh()->allowed_attachment_types)->toBe(['images', 'audio']);
});
