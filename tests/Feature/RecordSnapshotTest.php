<?php

use App\Models\Ticket;
use App\Workflows\RecordSnapshot;

it('says whether a ticket mirrors an issue from an external tracker', function () {
    $own = Ticket::factory()->create();
    $mirrored = Ticket::factory()->create(['external_source' => 'backlog', 'external_id' => 'ISS-1']);

    expect(RecordSnapshot::ticket($own)['ticket']['is_external'])->toBeFalse()
        ->and(RecordSnapshot::ticket($mirrored)['ticket']['is_external'])->toBeTrue();
});
