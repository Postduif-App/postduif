<?php

use App\Enums\WorkflowFieldType;
use App\Features\Webhooks;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\MessageKeywordTrigger;
use App\Workflows\Triggers\ReactionTrigger;
use App\Workflows\Triggers\WebhookTrigger;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowRegistry;
use App\Workflows\WorkflowStepContext;
use App\Workflows\WorkflowTrigger;
use Laravel\Pennant\Feature;

/** A trigger that exists only here, to prove the register does not care. */
class MadeUpTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return 'Verzonnen';
    }

    public static function description(): string
    {
        return 'Bestaat alleen in deze test.';
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return ['thing' => 'Iets'];
    }
}

class MadeUpAction extends WorkflowAction
{
    public static function label(): string
    {
        return 'Doe iets';
    }

    public static function description(): string
    {
        return 'Bestaat alleen in deze test.';
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [WorkflowField::text('body', 'Tekst')];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        return ['said' => $context->setting('body')];
    }
}

it('names a trigger after its class, without the word trigger on the end', function () {
    expect(MessageKeywordTrigger::key())->toBe('message-keyword')
        ->and(ReactionTrigger::key())->toBe('reaction')
        ->and(WebhookTrigger::key())->toBe('webhook');
});

it('names an action after its class', function () {
    expect(MadeUpAction::key())->toBe('made-up-action');
});

it('offers every trigger the application was built with', function () {
    $registry = app(WorkflowRegistry::class);

    expect(array_keys($registry->triggers()))->toBe([
        'message-keyword',
        'channel-join',
        'reaction',
        'form-submitted',
        'timeclock',
        'link',
        'slash-command',
        'button',
        'schedule',
        'webhook',
    ]);
});

it('hands back nothing rather than falling over for a key it does not know', function () {
    $registry = app(WorkflowRegistry::class);

    expect($registry->trigger('verzonnen'))->toBeNull()
        ->and($registry->action('verzonnen'))->toBeNull()
        ->and($registry->resolveAction('verzonnen'))->toBeNull();
});

it('refuses two things answering to one key', function () {
    $registry = new WorkflowRegistry(triggers: [MadeUpTrigger::class]);

    expect(fn () => $registry->registerTrigger(MadeUpTrigger::class))
        ->toThrow(InvalidArgumentException::class, "sleutel 'made-up'");
});

it('builds an action out of the container so it can ask for what it needs', function () {
    $registry = new WorkflowRegistry(actions: [MadeUpAction::class]);

    expect($registry->resolveAction('made-up-action'))->toBeInstanceOf(MadeUpAction::class);
});

it('lets a variable into the fields that can be resolved, and keeps it out of the rest', function () {
    expect(WorkflowField::text('body', 'Tekst')->acceptsVariables())->toBeTrue()
        ->and(WorkflowField::longText('body', 'Tekst')->acceptsVariables())->toBeTrue()
        /*
         * The channel is in because a step looks it up inside its own
         * workspace, by name or by id — so a variable can only ever find
         * something this workspace owns. See WorkflowFieldType.
         */
        ->and(WorkflowField::channel('channel_id', 'Kanaal')->acceptsVariables())->toBeTrue()
        // And the form stays out: a ULID with no name to fall back on.
        ->and(WorkflowField::form('form_id', 'Formulier')->acceptsVariables())->toBeFalse()
        ->and(WorkflowField::number('minutes', 'Minuten')->acceptsVariables())->toBeFalse()
        ->and(WorkflowField::member('user_id', 'Wie')->acceptsVariables())->toBeFalse();
});

it('describes a field completely enough for a screen to draw it', function () {
    $field = WorkflowField::choice('cadence', 'Hoe vaak', ['daily' => 'Elke dag'], required: false);

    expect($field->toArray())->toBe([
        'key' => 'cadence',
        'type' => WorkflowFieldType::Choice->value,
        'label' => 'Hoe vaak',
        'hint' => null,
        'required' => false,
        'acceptsVariables' => false,
        'options' => ['daily' => 'Elke dag'],
    ]);
});

it('says what a trigger will hand over, in words somebody can read', function () {
    $provides = MessageKeywordTrigger::provides();

    expect($provides)->toHaveKeys(['message.text', 'channel.name', 'user.name', 'keyword'])
        ->and($provides['message.text'])->toBe('Wat er in het bericht staat');
});

it('keeps the two people in a reaction apart', function () {
    $provides = ReactionTrigger::provides();

    expect($provides['user.name'])->toBe('De naam van wie de emoji zette')
        ->and($provides['author.name'])->toBe('De naam van wie het bericht schreef');
});

it('lets every trigger be used, except the webhook one where webhooks are off', function () {
    $workspace = Workspace::factory()->create();
    $workflow = Workflow::factory()->create(['workspace_id' => $workspace->id]);

    expect(WebhookTrigger::availableFor($workflow))->toBeTrue()
        ->and(MessageKeywordTrigger::availableFor($workflow))->toBeTrue();

    Feature::for($workspace)->deactivate(Webhooks::class);

    expect(WebhookTrigger::availableFor($workflow->fresh()))->toBeFalse()
        ->and(MessageKeywordTrigger::availableFor($workflow->fresh()))->toBeTrue();
});

it('hands the builder every trigger with its fields and its variables', function () {
    $described = app(WorkflowRegistry::class)->toArray();

    $keyword = collect($described['triggers'])->firstWhere('key', 'message-keyword');

    expect($keyword['label'])->toBe('Als iemand een woord zegt')
        ->and($keyword['fields'])->toHaveCount(2)
        ->and($keyword['fields'][0]['key'])->toBe('keywords')
        ->and($keyword['fields'][0]['required'])->toBeTrue()
        // The channel is the optional one: empty means the whole workspace.
        ->and($keyword['fields'][1]['key'])->toBe('channel_id')
        ->and($keyword['fields'][1]['required'])->toBeFalse()
        ->and($keyword['provides'])->toHaveKey('message.text');
});

it('gives every trigger a name and a sentence in both languages', function () {
    $registry = app(WorkflowRegistry::class);

    foreach (['nl', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ($registry->triggers() as $key => $trigger) {
            expect($trigger::label())->not->toContain('workflows.')
                ->and($trigger::description())->not->toContain('workflows.');

            foreach ($trigger::fields() as $field) {
                expect($field->label)->not->toContain('workflows.');
            }

            foreach ($trigger::provides() as $path => $what) {
                expect($what)->not->toContain('workflows.', "{$key} beschrijft {$path} niet");
            }
        }
    }
});
