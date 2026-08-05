<?php

use App\Actions\Chat\SendMessage;
use App\Enums\SystemRole;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A workflow with a face, or the beginnings of one.
 *
 * @return array{0: User, 1: Workflow}
 */
function workflowWithFace(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Admin);

    Feature::for($workspace)->activate(WorkflowsFeature::class);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'name' => 'Storingsmelder',
    ]);

    return [$owner, $workflow];
}

it('gives a workflow a face, squared and shrunk like any other', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)
        ->post(route('workflows.avatar.store', $workflow), [
            'avatar' => UploadedFile::fake()->image('bot.jpg', 900, 600),
        ])
        ->assertRedirect();

    $workflow->refresh();

    expect($workflow->avatar_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($workflow->avatar_path))->toBeTrue();

    $image = getimagesizefromstring((string) Storage::disk('local')->get($workflow->avatar_path));

    expect($image[0])->toBe($image[1]);
});

it('refuses a script in a costume', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)
        ->post(route('workflows.avatar.store', $workflow), [
            'avatar' => UploadedFile::fake()->create('bot.svg', 8, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('avatar');

    expect($workflow->fresh()->avatar_path)->toBeNull();
});

it('takes the picture away again, file and all', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)->post(route('workflows.avatar.store', $workflow), [
        'avatar' => UploadedFile::fake()->image('bot.jpg'),
    ]);

    $path = $workflow->fresh()->avatar_path;

    actingAs($owner)
        ->delete(route('workflows.avatar.destroy', $workflow))
        ->assertRedirect();

    expect($workflow->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('hands the face to the people in the workspace and nobody else', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)->post(route('workflows.avatar.store', $workflow), [
        'avatar' => UploadedFile::fake()->image('bot.jpg'),
    ]);

    actingAs($owner)
        ->get(route('avatars.workflow', $workflow))
        ->assertOk()
        ->assertHeader('content-type', 'image/webp');

    $stranger = User::factory()->create();
    workspaceWithMember($stranger);

    // 404 rather than 403: a picture from another organisation is not something
    // to be told about.
    actingAs($stranger)
        ->get(route('avatars.workflow', $workflow))
        ->assertNotFound();
});

it('answers nothing for a workflow that never got one', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)
        ->get(route('avatars.workflow', $workflow))
        ->assertNotFound();

    expect($workflow->avatarUrl())->toBeNull();
});

it('puts the face on the messages that workflow posts', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)->post(route('workflows.avatar.store', $workflow), [
        'avatar' => UploadedFile::fake()->image('bot.jpg'),
    ]);

    $channel = channelWithMember($workflow->workspace, $owner);

    $message = app(SendMessage::class)->fromSystem(
        $channel,
        'Er is een storing gemeld.',
        $workflow->botName(),
        workflow: $workflow->fresh(),
    );

    $author = present($message)['author'];

    expect($author['isBot'])->toBeTrue()
        ->and($author['name'])->toBe('Storingsmelder')
        ->and($author['avatarUrl'])->toBe($workflow->fresh()->avatarUrl());
});

it('draws the default mark for a bot with no picture', function () {
    [$owner, $workflow] = workflowWithFace();

    $channel = channelWithMember($workflow->workspace, $owner);

    $message = app(SendMessage::class)->fromSystem(
        $channel,
        'Er is een storing gemeld.',
        $workflow->botName(),
        workflow: $workflow,
    );

    expect(present($message)['author']['avatarUrl'])->toBeNull();
});

it('keeps a message signed after the workflow behind it is gone', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)->post(route('workflows.avatar.store', $workflow), [
        'avatar' => UploadedFile::fake()->image('bot.jpg'),
    ]);

    $channel = channelWithMember($workflow->workspace, $owner);

    $message = app(SendMessage::class)->fromSystem(
        $channel,
        'Er is een storing gemeld.',
        $workflow->botName(),
        workflow: $workflow->fresh(),
    );

    $workflow->delete();

    $author = present($message->fresh())['author'];

    /*
     * The name was copied onto the message and survives; the face was pointed
     * at and does not. That is the whole shape of the decision — see the
     * avatar migration.
     */
    expect($author['name'])->toBe('Storingsmelder')
        ->and($author['avatarUrl'])->toBeNull();
});

it('says on the builder whether there is a face to show', function () {
    [$owner, $workflow] = workflowWithFace();

    actingAs($owner)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workflow.avatarUrl', null));

    actingAs($owner)->post(route('workflows.avatar.store', $workflow), [
        'avatar' => UploadedFile::fake()->image('bot.jpg'),
    ]);

    actingAs($owner)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.avatarUrl', $workflow->fresh()->avatarUrl()));
});

it('keeps somebody out of a workflow that is not their workspace\'s', function () {
    [, $workflow] = workflowWithFace();

    $stranger = User::factory()->create();
    $elsewhere = workspaceWithMember($stranger, SystemRole::Admin);

    Feature::for($elsewhere)->activate(WorkflowsFeature::class);

    actingAs($stranger)
        ->post(route('workflows.avatar.store', $workflow), [
            'avatar' => UploadedFile::fake()->image('bot.jpg'),
        ])
        ->assertNotFound();

    expect($workflow->fresh()->avatar_path)->toBeNull();
});
