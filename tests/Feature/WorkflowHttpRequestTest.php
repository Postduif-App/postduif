<?php

use App\Actions\Workflows\RunWorkflow;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The guard resolves names, and a test that hits DNS is a test that fails on a
 * train. Every address here is written as an address for that reason.
 */

it('asks the far end and files what it said under the step', function () {
    Http::fake([
        '*' => Http::response(['order' => ['id' => 42, 'state' => 'open']], 200),
    ]);

    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/orders/42',
    ]);

    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.status'))->toBe(200)
        ->and(data_get($run->context, 'steps.0.ok'))->toBeTrue()
        // The whole point: a later step writes {{ steps.0.json.order.id }}.
        ->and(data_get($run->context, 'steps.0.json.order.id'))->toBe(42);
});

it('lets a later step write what the answer said', function () {
    Http::fake(['*' => Http::response(['naam' => 'Pietje'])]);

    [$workflow, , , $channel] = workflowWithChannel();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/wie',
    ])->create();

    WorkflowStep::factory()->for($workflow)->at(1)->doing('send-channel-message', [
        'channel_id' => $channel->id,
        'body' => 'Hallo {{ steps.0.json.naam }}.',
    ])->create();

    $run = WorkflowRun::factory()->for($workflow)->create(['context' => ['depth' => 1]]);

    app(RunWorkflow::class)->handle($run);

    expect($channel->messages()->latest('id')->first()->body)->toBe('Hallo Pietje.');
});

it('sends what the step was given, to the address the variables made', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    [$workflow] = workflowWithChannel();

    runStep($workflow, 'http-request', [
        'method' => 'post',
        'url' => 'https://93.184.216.34/melden/{{ trigger.user.id }}',
        'headers' => "Authorization: Bearer geheim\nX-Bron: pcom",
        'body' => '{"wie":"{{ trigger.user.name }}"}',
    ], ['trigger' => ['user' => ['id' => 7, 'name' => 'Pietje']]]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://93.184.216.34/melden/7'
            && $request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer geheim']
            && $request->header('X-Bron') === ['pcom']
            // Typed as JSON and sent as typed: a body run through an encoder
            // again would quietly repair what somebody wrote.
            && $request->body() === '{"wie":"Pietje"}'
            && $request->header('Content-Type') === ['application/json'];
    });
});

it('keeps an answer that is not JSON as text rather than as nothing', function () {
    Http::fake(['*' => Http::response('gewoon tekst', 201)]);

    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/tekst',
    ]);

    expect(data_get($run->context, 'steps.0.body'))->toBe('gewoon tekst')
        ->and(data_get($run->context, 'steps.0.json'))->toBeNull()
        // 201 is a success as much as 200 is, which is the whole reason `ok`
        // exists beside the number.
        ->and(data_get($run->context, 'steps.0.ok'))->toBeTrue();
});

it('does not fail a run over an answer the far end did not like', function () {
    Http::fake(['*' => Http::response(['fout' => 'nee'], 422)]);

    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/nee',
    ]);

    /*
     * A 422 is an answer, not a breakdown. Failing the run would take the
     * decision away from the workflow — which can ask about `ok` itself, and
     * stop or fork on it.
     */
    expect($run->status)->toBe(WorkflowRunStatus::Succeeded)
        ->and(data_get($run->context, 'steps.0.ok'))->toBeFalse()
        ->and(data_get($run->context, 'steps.0.status'))->toBe(422);
});

it('keeps only as much of an answer as it said it would', function () {
    $limit = (int) config('workflows.http.max_response_bytes');

    Http::fake(['*' => Http::response(str_repeat('a', $limit + 500))]);

    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/veel',
    ]);

    $kept = data_get($run->context, 'steps.0.body');

    expect(strlen($kept))->toBeLessThan($limit + 100)
        // Said out loud, because half a sentence should not read as the whole
        // of one.
        ->and($kept)->toEndWith(__('workflows.value.truncated'));
});

it('refuses an address inside the network this server stands in', function () {
    Http::fake();

    [$workflow] = workflowWithChannel();

    foreach ([
        'http://127.0.0.1/admin',
        'http://169.254.169.254/latest/meta-data/',
        'http://10.1.2.3/intern',
        'http://192.168.1.1/router',
        'http://[::1]/',
    ] as $url) {
        $run = runStep($workflow, 'http-request', ['method' => 'get', 'url' => $url]);

        expect($run->status)->toBe(WorkflowRunStatus::Failed, "{$url} had geweigerd moeten worden")
            ->and($run->stepRuns->first()->status)->toBe(WorkflowStepStatus::Failed);
    }

    // Nothing left the machine at all: the address is refused before a client
    // is ever handed it.
    Http::assertNothingSent();
});

it('refuses a scheme that is not a request over a network', function () {
    expect(fn () => app(GuardOutboundUrl::class)->handle('file:///etc/passwd'))
        ->toThrow(RuntimeException::class, __('workflows.errors.url_scheme'));

    expect(fn () => app(GuardOutboundUrl::class)->handle('geen adres'))
        ->toThrow(RuntimeException::class, __('workflows.errors.url_unreadable'));
});

it('lets a public address through', function () {
    expect(app(GuardOutboundUrl::class)->handle('https://93.184.216.34/iets'))
        ->toBe('https://93.184.216.34/iets');
});

it('follows no redirect, whatever the far end suggests', function () {
    Http::fake([
        '*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    [$workflow] = workflowWithChannel();

    $run = runStep($workflow, 'http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/stuur-me-door',
    ]);

    /*
     * The redirect is handed to the workflow as what it is — a 302 — rather
     * than followed. Following it would mean reaching an address nothing ever
     * approved, which is the ordinary way a feature like this ends up at the
     * metadata endpoint after all.
     */
    expect(data_get($run->context, 'steps.0.status'))->toBe(302);

    Http::assertSentCount(1);
});

it('lets a development machine reach its own localhost when it says so', function () {
    config()->set('workflows.http.allow_private_hosts', true);

    expect(app(GuardOutboundUrl::class)->handle('http://127.0.0.1:8000/api'))
        ->toBe('http://127.0.0.1:8000/api');
});

it('offers the paths the last answer actually had', function () {
    [$workflow, , $admin] = workflowWithChannel();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('http-request', [
        'method' => 'get',
        'url' => 'https://93.184.216.34/order',
    ])->create();

    WorkflowRun::factory()->for($workflow)->create([
        'context' => [
            'steps' => [['json' => ['order' => ['id' => 42, 'regels' => [['sku' => 'A1']]]]]],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            /*
             * The register can only promise the word "json" — the shape under
             * it belongs to whoever answered. The last run is the one honest
             * source for the rest, and reading it here saves somebody guessing
             * at a path until they have run the workflow once.
             */
            ->where('samples', [
                'steps.0.json' => ['order.id', 'order.regels.0.sku'],
            ])
        );
});

it('offers nothing where the step at that place is no longer an HTTP step', function () {
    [$workflow, , $admin] = workflowWithChannel();

    WorkflowStep::factory()->for($workflow)->at(0)->doing('add-reaction', ['emoji' => '👋'])->create();

    WorkflowRun::factory()->for($workflow)->create([
        'context' => ['steps' => [['json' => ['order' => ['id' => 42]]]]],
    ]);

    $this->actingAs($admin)
        ->get(route('workflows.edit', $workflow))
        ->assertOk()
        // Editing a workflow must not leave one action wearing another's
        // vocabulary.
        ->assertInertia(fn ($page) => $page->where('samples', []));
});
