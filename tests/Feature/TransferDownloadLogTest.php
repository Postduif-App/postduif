<?php

use App\Enums\TransferAudience;
use App\Enums\WorkspaceRole;
use App\Models\Transfer;
use App\Models\TransferDownload;
use App\Models\TransferRecipient;
use App\Models\User;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('records who fetched what, and when', function () {
    [$transfer] = waitingTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertOk();

    expect(TransferDownload::sole())
        ->transfer_id->toBe($transfer->id)
        ->media_id->toBe($media->id)
        ->wasWholeArchive()->toBeFalse()
        ->created_at->not->toBeNull();
});

/** The archive is one handover of the whole pile, so no single file is named. */
it('records the archive as the lot rather than as a file', function () {
    [$transfer] = waitingTransfer(files: 3);

    get(route('transfers.download-all', $transfer->token))->assertOk();

    expect(TransferDownload::sole())
        ->media_id->toBeNull()
        ->wasWholeArchive()->toBeTrue();
});

/**
 * Written in the same transaction as the counter. If they could drift, the log
 * would be the one a sender acts on while the counter is the one enforced.
 */
it('keeps the log and the counter in step', function () {
    [$transfer] = waitingTransfer(files: 2);
    $media = $transfer->files();

    get(route('transfers.download', [$transfer->token, $media[0]->id]))->assertOk();
    get(route('transfers.download', [$transfer->token, $media[1]->id]))->assertOk();
    get(route('transfers.download-all', $transfer->token))->assertOk();

    expect($transfer->refresh()->downloads)->toBe(3)
        ->and(TransferDownload::count())->toBe(3);
});

/** A refused fetch is not a fetch, and must leave no trace of having happened. */
it('records nothing when the link hands nothing over', function () {
    [$transfer] = waitingTransfer(['revoked_at' => now()]);
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertGone();

    expect(TransferDownload::count())->toBe(0);
});

it('names the address a personal link was given to', function () {
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'audience' => TransferAudience::NamedRecipients,
    ]);
    $transfer->addMedia(UploadedFile::fake()->createWithContent('offerte.pdf', 'inhoud'))
        ->toMediaCollection(Transfer::FILES);

    $recipient = TransferRecipient::factory()->create([
        'transfer_id' => $transfer->id,
        'email' => 'anna@klant.nl',
    ]);

    get(route('transfers.download', [$recipient->token, $transfer->refresh()->files()->first()->id]))
        ->assertOk();

    expect(TransferDownload::sole()->transfer_recipient_id)->toBe($recipient->id);

    actingAs($sender)
        ->get(route('chat.transfers.index', $transfer->workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transfers.0.downloadLog', 1)
            ->where('transfers.0.downloadLog.0.who', 'anna@klant.nl')
            ->where('transfers.0.downloadLog.0.wasWholeArchive', false)
        );
});

/** Most downloads have nobody to attribute them to, and the screen must say so. */
it('leaves the who empty for an open link', function () {
    [$transfer, , $sender] = waitingTransfer();

    get(route('transfers.download-all', $transfer->token))->assertOk();

    actingAs($sender)
        ->get(route('chat.transfers.index', $transfer->workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfers.0.downloadLog.0.who', null)
            ->where('transfers.0.downloadLog.0.wasWholeArchive', true)
        );
});

it('names the member behind a members-only fetch', function () {
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->membersOnly()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);
    $transfer->addMedia(UploadedFile::fake()->createWithContent('intern.txt', 'inhoud'))
        ->toMediaCollection(Transfer::FILES);

    actingAs($sender)
        ->get(route('transfers.download-all', $transfer->token))
        ->assertOk();

    expect(TransferDownload::sole()->user_id)->toBe($sender->id);
});

/**
 * Enough to answer "is my link doing the rounds", and no more: a full history
 * on a settings screen is a table nobody reads holding IP addresses nobody
 * looked at.
 */
it('shows the recent handovers rather than the whole history', function () {
    [$transfer, , $sender] = waitingTransfer();

    TransferDownload::factory()->count(15)->create(['transfer_id' => $transfer->id]);

    actingAs($sender)
        ->get(route('chat.transfers.index', $transfer->workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transfers.0.downloadLog', 10)
            ->where('transfers.0.downloads', 0)
        );
});

it('tells the sender when it was last fetched', function () {
    [$transfer, , $sender] = waitingTransfer();

    get(route('transfers.download-all', $transfer->token))->assertOk();

    actingAs($sender)
        ->get(route('chat.transfers.index', $transfer->workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'transfers.0.lastDownloadedAt',
            fn ($value) => $value !== null,
        ));
});

/**
 * The log holds an IP address, which is personal data about somebody who may
 * have no account here. It must not outlive the transfer it is about.
 */
it('goes when the transfer goes', function () {
    [$transfer] = waitingTransfer();

    get(route('transfers.download-all', $transfer->token))->assertOk();
    expect(TransferDownload::count())->toBe(1);

    $transfer->delete();

    expect(TransferDownload::count())->toBe(0);
});

it('does not show a colleague what happened to somebody else transfer', function () {
    [, $workspace] = waitingTransfer();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($colleague)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 0));
});
