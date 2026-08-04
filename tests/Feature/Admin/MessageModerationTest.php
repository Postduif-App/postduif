<?php

use App\Enums\ChannelType;
use App\Enums\InboxItemType;
use App\Events\MessageDeleted;
use App\Filament\Resources\Messages\MessageResource;
use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function moderatedMessage(array $attributes = []): Message
{
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    return Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        ...$attributes,
    ]);
}

test('it lists messages from every workspace', function () {
    $messages = collect(range(1, 3))->map(fn () => moderatedMessage());

    Livewire::test(ListMessages::class)
        ->assertCanSeeTableRecords($messages);
});

test('it filters messages down to one workspace', function () {
    $wanted = moderatedMessage();
    $other = moderatedMessage();

    Livewire::test(ListMessages::class)
        ->filterTable('workspace', $wanted->workspace_id)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it filters messages by author', function () {
    $wanted = moderatedMessage();
    $other = moderatedMessage();

    Livewire::test(ListMessages::class)
        ->filterTable('author', $wanted->user_id)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it searches the body of a message', function () {
    $wanted = moderatedMessage(['body' => 'iets volstrekt onaanvaardbaars']);
    $other = moderatedMessage(['body' => 'gewoon een gesprek over werk']);

    Livewire::test(ListMessages::class)
        ->searchTable('onaanvaardbaars')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it deletes a message through the chat action so the channel hears about it', function () {
    Event::fake([MessageDeleted::class]);

    $message = moderatedMessage();
    InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => $message->id,
        'user_id' => User::factory()->create()->id,
        'channel_id' => $message->channel_id,
    ]);

    Livewire::test(ListMessages::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($message));

    expect($message->fresh()->deleted_at)->not->toBeNull()
        ->and(InboxItem::where('message_id', $message->id)->exists())->toBeFalse();

    Event::assertDispatched(MessageDeleted::class);
});

test('it offers no way to edit somebody elses message', function () {
    expect(array_keys(MessageResource::getPages()))
        ->not->toContain('edit')
        ->not->toContain('create');
});

test('it keeps an ordinary user out of the message list', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/messages')
        ->assertForbidden();
});

test('it lists messages from a direct message channel', function () {
    $workspace = Workspace::factory()->create();
    $dm = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Direct,
        'name' => null,
        'slug' => null,
    ]);
    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $dm->id,
    ]);

    /**
     * The page request, not the Livewire component: the crash this guards
     * against happened while the channel filter built its option labels, which
     * only runs once a real page is rendered.
     */
    $this->get('/admin/messages')->assertSuccessful();

    Livewire::test(ListMessages::class)
        ->assertCanSeeTableRecords([$message])
        ->filterTable('channel', $dm->getKey())
        ->assertCanSeeTableRecords([$message]);
});
