<?php

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * A file shared in some channel, with everything the overview shows behind it.
 *
 * @param  array<string, mixed>  $attributes
 */
function sharedFile(string $name = 'notulen.pdf', int $kilobytes = 12, array $attributes = []): Media
{
    $workspace = Workspace::factory()->create($attributes);
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    return $message
        ->addMedia(UploadedFile::fake()->create($name, $kilobytes, 'application/pdf'))
        ->toMediaCollection(Message::ATTACHMENTS);
}

test('it lists files shared anywhere on the platform', function () {
    $files = collect(range(1, 3))->map(fn (int $i) => sharedFile("bestand-{$i}.pdf"));

    Livewire::test(ListAttachments::class)
        ->assertCanSeeTableRecords($files);
});

test('it sorts by size, so the biggest are one click away', function () {
    $small = sharedFile('klein.pdf', 5);
    $large = sharedFile('groot.pdf', 500);

    Livewire::test(ListAttachments::class)
        ->sortTable('size', 'desc')
        ->assertCanSeeTableRecords([$large, $small], inOrder: true);
});

test('it narrows down to one workspace', function () {
    $wanted = sharedFile('hier.pdf');
    $elsewhere = sharedFile('daar.pdf');

    Livewire::test(ListAttachments::class)
        ->filterTable('workspace', $wanted->model->workspace_id)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

/**
 * The media table is shared by every model that keeps files. Anything not
 * hanging on a message is somebody else's row and has no place in this list.
 */
test('it leaves media that belongs to something else alone', function () {
    $onAMessage = sharedFile();

    $elsewhere = Media::query()->create([
        'model_type' => User::class,
        'model_id' => (string) User::factory()->create()->id,
        'collection_name' => 'avatar',
        'name' => 'portret',
        'file_name' => 'portret.png',
        'disk' => 'local',
        'size' => 100,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    Livewire::test(ListAttachments::class)
        ->assertCanSeeTableRecords([$onAMessage])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});
