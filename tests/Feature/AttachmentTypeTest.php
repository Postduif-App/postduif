<?php

use App\Enums\AttachmentType;
use App\Models\Workspace;

it('recognises a file by the group it belongs to', function (string $mime, ?AttachmentType $expected) {
    $matching = collect(AttachmentType::cases())
        ->filter(fn (AttachmentType $type): bool => $type->accepts($mime))
        ->values();

    expect($matching->all())->toBe($expected === null ? [] : [$expected]);
})->with([
    'png' => ['image/png', AttachmentType::Images],
    'mp4' => ['video/mp4', AttachmentType::Video],
    'any video' => ['video/quicktime', AttachmentType::Video],
    'mp3' => ['audio/mpeg', AttachmentType::Audio],
    'pdf' => ['application/pdf', AttachmentType::Documents],
    'zip' => ['application/zip', AttachmentType::Archives],
    // No group takes these, so no setting can ever let them in.
    'html' => ['text/html', null],
    'svg' => ['image/svg+xml', null],
    'php' => ['application/x-httpd-php', null],
]);

it('reads a type in whatever case it arrives', function () {
    expect(AttachmentType::Images->accepts('IMAGE/PNG'))->toBeTrue()
        ->and(AttachmentType::Images->accepts(' image/png '))->toBeTrue();
});

it('lets the workspace decide what comes in', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => [AttachmentType::Images->value],
    ]);

    expect($workspace->acceptsAttachment('image/png'))->toBeTrue()
        ->and($workspace->acceptsAttachment('application/pdf'))->toBeFalse();
});

it('accepts nothing at all once sharing is off', function () {
    $workspace = Workspace::factory()->create([
        'uploads_enabled' => false,
        'allowed_attachment_types' => [AttachmentType::Images->value],
    ]);

    expect($workspace->acceptsAttachment('image/png'))->toBeFalse();
});

/**
 * The column is not nullable, so a saved workspace always has a list. A model
 * that has not been saved yet does not, and it still has to answer.
 */
it('falls back to the defaults before anything was ever chosen', function () {
    expect((new Workspace)->allowedAttachmentTypes())
        ->toBe(array_map(
            fn (string $value): AttachmentType => AttachmentType::from($value),
            AttachmentType::defaults(),
        ));
});

it('ignores a stored group that no longer exists', function () {
    $workspace = Workspace::factory()->create([
        'allowed_attachment_types' => ['images', 'holografische-projecties'],
    ]);

    expect($workspace->allowedAttachmentTypes())->toBe([AttachmentType::Images]);
});
