<?php

use App\Actions\Contracts\NotifyContractAuthor;
use App\Actions\Contracts\RenderSignedContract;
use App\Actions\Contracts\SendSignedContract;
use App\Enums\ContractProgressKind;
use App\Enums\ContractStatus;
use App\Enums\InboxItemType;
use App\Enums\SystemRole;
use App\Features\Contracts as ContractsFeature;
use App\Jobs\RenderSignedContractJob;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ContractProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

use function Pest\Laravel\post;

/**
 * Telling the person who asked for the signatures.
 *
 * The rule the whole bead turns on is that the three pieces of news must not
 * read the same. "Anna heeft getekend, nog twee te gaan" and "iedereen is
 * langs geweest" ask different things of the reader, and a feature that sent
 * one wording for both would teach somebody to skim past the one that means the
 * work is done.
 *
 * The other rule is about timing: the completion is announced by the job that
 * composes the PDF, not by the signing, because the message the author wants
 * has a download link in it.
 *
 * @return array{0: Contract, 1: User, 2: Channel}
 */
function contractWithNotifications(array $state = []): array
{
    Storage::fake('local');

    $author = User::factory()->create();
    $workspace = workspaceWithMember($author, SystemRole::Admin);

    Feature::for($workspace)->activate(ContractsFeature::class);

    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    $contract = Contract::factory()->sent()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $author->id,
        'title' => 'Huurovereenkomst 2026',
        'notify_channel_id' => $channel->id,
        'page_count' => 1,
        ...$state,
    ]);

    $contract->addMedia(realPdf(1))->toMediaCollection(Contract::SOURCE);
    $contract->update(['source_hash' => hash_file('sha256', $contract->source()->getPath())]);

    return [$contract->fresh(['signers']), $author, $channel];
}

it('tells the author that one of several has signed, with the number left', function () {
    Notification::fake();
    Queue::fake();

    [$contract, $author] = contractWithNotifications();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id, 'name' => 'Anna de Vries']);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token))->assertSessionHasNoErrors();

    Notification::assertSentTo(
        $author,
        ContractProgress::class,
        fn (ContractProgress $notification): bool => $notification->kind === ContractProgressKind::Signed
            && $notification->signer?->is($first) === true

            /*
             * No link, because there is no finished document yet — and one
             * person of two having signed is not something to hand a download
             * for.
             */
            && $notification->downloadUrl === null,
    );
});

it('does not announce the signing itself when it was the last one', function () {
    Notification::fake();
    Queue::fake();

    [$contract] = contractWithNotifications();

    $only = ContractSigner::factory()->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $only->token));

    /*
     * The author hears once, from the job, with the document attached to the
     * message. Two notifications a second apart — one saying somebody signed
     * and one saying it is complete — would be the feature talking to itself.
     */
    Notification::assertNothingSent();
    Queue::assertPushed(RenderSignedContractJob::class);
});

it('announces the completion from the job, with the download in it', function () {
    Notification::fake();

    [$contract, $author] = contractWithNotifications();

    $only = ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);

    $contract->update(['status' => ContractStatus::Completed, 'completed_at' => now()]);

    (new RenderSignedContractJob($contract->id))->handle(
        app(RenderSignedContract::class),
        app(NotifyContractAuthor::class),
        app(SendSignedContract::class),
    );

    Notification::assertSentTo(
        $author,
        ContractProgress::class,
        fn (ContractProgress $notification): bool => $notification->kind === ContractProgressKind::Completed
            && $notification->downloadUrl !== null,
    );

    expect($only->fresh()->hasSigned())->toBeTrue();
});

it('still tells the author when the copy could not be made', function () {
    Notification::fake();

    [$contract, $author] = contractWithNotifications();

    ContractSigner::factory()->signed()->create(['contract_id' => $contract->id]);
    $contract->update(['status' => ContractStatus::Completed, 'completed_at' => now()]);

    (new RenderSignedContractJob($contract->id))->failed(new RuntimeException('kapot'));

    /*
     * Silence would be a worse failure than the one that happened: the
     * contract is signed, and somebody would sit waiting for news that had
     * already arrived. The notification simply carries no link.
     */
    Notification::assertSentTo(
        $author,
        ContractProgress::class,
        fn (ContractProgress $notification): bool => $notification->kind === ContractProgressKind::Completed
            && $notification->downloadUrl === null,
    );
});

it('tells the author about a refusal, with the reason', function () {
    Notification::fake();
    Queue::fake();

    [$contract, $author] = contractWithNotifications();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id, 'name' => 'Bram']);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.decline', $first->token), ['reason' => 'Niet akkoord met artikel 4.']);

    // The one of the three the author most urgently needs to read: it is the
    // only one that means something has to change.
    Notification::assertSentTo(
        $author,
        ContractProgress::class,
        fn (ContractProgress $notification): bool => $notification->kind === ContractProgressKind::Declined
            && $notification->signer?->decline_reason === 'Niet akkoord met artikel 4.',
    );
});

it('writes a line in the channel the author named', function () {
    Queue::fake();

    [$contract, , $channel] = contractWithNotifications();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id, 'name' => 'Anna de Vries']);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token));

    $message = Message::query()->where('channel_id', $channel->id)->sole();

    expect($message->body)->toContain('Anna de Vries')
        ->and($message->body)->toContain('Huurovereenkomst 2026')
        ->and($message->bot_name)->toBe(NotifyContractAuthor::BOT_NAME);
});

it('puts one row in the inbox however many people sign', function () {
    Queue::fake();

    [$contract, $author] = contractWithNotifications();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    $second = ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(2)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token));
    post(route('contracts.sign.complete', $second->token));

    /*
     * A contract signed by four people over a week is one line in the inbox,
     * not four. What it points at is the newest message.
     */
    $rows = InboxItem::query()
        ->where('user_id', $author->id)
        ->where('type', InboxItemType::ContractProgress)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->read_at)->toBeNull();
});

it('marks the row unread again when more news arrives', function () {
    Queue::fake();

    [$contract, $author] = contractWithNotifications();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    $second = ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(2)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token));

    InboxItem::query()->where('user_id', $author->id)->update(['read_at' => now()]);

    post(route('contracts.sign.complete', $second->token));

    // News that arrived after somebody marked the row off is news they have
    // not seen.
    expect(InboxItem::query()->where('user_id', $author->id)->sole()->read_at)->toBeNull();
});

it('writes to the conversation with the signer when they are a colleague', function () {
    Queue::fake();

    [$contract, $author, $channel] = contractWithNotifications();

    $colleague = User::factory()->create();
    joinWorkspace($contract->workspace, $colleague, SystemRole::Member);

    $signer = ContractSigner::factory()->forUser($colleague)->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $signer->token));

    /*
     * The DM comes first because it is the one place where the reply the author
     * may want to write — "dank je, ik stuur de factuur" — is already addressed
     * to the right person.
     */
    expect(Message::query()->where('channel_id', $channel->id)->count())->toBe(0);

    $dm = Message::query()->sole();

    expect($dm->channel->isDirect())->toBeTrue();
});

it('says nothing in the chat when there is nowhere to say it', function () {
    Queue::fake();
    Notification::fake();

    [$contract, $author] = contractWithNotifications(['notify_channel_id' => null]);

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token))->assertSessionHasNoErrors();

    /*
     * An ordinary answer rather than a failure: a contract sent to strangers
     * with no channel named has no chat to appear in, and the mail is then the
     * whole of the notification.
     */
    expect(Message::count())->toBe(0)
        ->and(InboxItem::count())->toBe(0);

    Notification::assertSentTo($author, ContractProgress::class);
});

it('respects somebody who has switched their notifications off', function () {
    Queue::fake();

    [$contract, $author, $channel] = contractWithNotifications();

    $author->forceFill(['notify_via_mail' => false, 'pushover_user_key' => null])->save();

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token))->assertSessionHasNoErrors();

    /*
     * Nothing goes out. via() returns an empty list for somebody who wants
     * neither mail nor a push, and Laravel then delivers on no channel at all —
     * which is why this asserts on the database rather than through
     * Notification::fake, whose assertions only see what a channel was chosen
     * for.
     */
    expect(DB::table('jobs')->count())->toBe(0);

    /*
     * The line in the channel and the row in the inbox are unaffected, and
     * deliberately so: those are not notifications. Switching off your mail is
     * saying "stuur me niets", not "houd dingen voor me achter in de app" —
     * and an inbox that skipped items for people who muted their mail would be
     * an inbox nobody could trust.
     */
    expect(Message::query()->where('channel_id', $channel->id)->count())->toBe(1)
        ->and(InboxItem::query()->where('user_id', $author->id)->count())->toBe(1);
});

it('keeps quiet when the author has left', function () {
    Queue::fake();
    Notification::fake();

    [$contract] = contractWithNotifications();

    $contract->update(['created_by' => null]);

    $first = ContractSigner::factory()->create(['contract_id' => $contract->id]);
    ContractSigner::factory()->inPosition(1)->create(['contract_id' => $contract->id]);

    post(route('contracts.sign.complete', $first->token))->assertSessionHasNoErrors();

    // A signed contract outlives whoever asked for it. The record stays; the
    // notification has nowhere to go.
    Notification::assertNothingSent();
    expect(Message::count())->toBe(0);
});
