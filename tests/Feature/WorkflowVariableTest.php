<?php

use App\Enums\WorkflowConditionMatch;
use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionOutcome;
use App\Workflows\EvaluateCondition;
use App\Workflows\ResolveVariables;
use App\Workflows\WorkflowField;

/** The memory of a run that was set off by somebody saying something. */
function runContext(): array
{
    return [
        'trigger' => [
            'message' => ['id' => '01J', 'text' => 'Er is een storing bij de klant'],
            'channel' => ['id' => 7, 'name' => 'support'],
            'user' => ['id' => 3, 'name' => 'Pietje'],
            'keyword' => 'storing',
            'urgent' => true,
            'payload' => ['order' => ['nummer' => 42]],
        ],
        'steps' => [['channel' => ['id' => 9]]],
    ];
}

it('puts what the trigger saw into the words of a step', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => 'Hoi {{ trigger.user.name }}, je zei "{{ trigger.keyword }}".'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['body'])->toBe('Hoi Pietje, je zei "storing".');
});

it('reaches into what an earlier step handed back', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => 'Kanaal {{ steps.0.channel.id }}'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['body'])->toBe('Kanaal 9');
});

it('leaves a gap rather than the path itself when it points at nothing', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => 'Hoi {{ trigger.user.bijnaam }}!'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['body'])->toBe('Hoi !');
});

it('keeps a variable out of a field that holds an id', function () {
    $config = ['channel_id' => '{{ trigger.channel.id }}', 'body' => '{{ trigger.channel.id }}'];

    $filled = app(ResolveVariables::class)->handle(
        $config,
        [WorkflowField::channel('channel_id', 'Kanaal'), WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['channel_id'])->toBe('{{ trigger.channel.id }}')
        ->and($filled['body'])->toBe('7');
});

it('writes a whole branch out as json rather than as the word Array', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => 'Binnengekomen: {{ trigger.payload }}'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['body'])->toBe('Binnengekomen: {"order":{"nummer":42}}');
});

it('says yes rather than nothing for something that is true', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => 'Urgent: {{ trigger.urgent }}'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    expect($filled['body'])->toBe('Urgent: ja');
});

it('does not let anything but a path through the braces', function () {
    $filled = app(ResolveVariables::class)->handle(
        ['body' => '{{ strtoupper(trigger.user.name) }} en {{ 1 + 1 }}'],
        [WorkflowField::text('body', 'Tekst')],
        runContext(),
    );

    // Left exactly as written: nothing here is a path, so nothing is replaced.
    expect($filled['body'])->toBe('{{ strtoupper(trigger.user.name) }} en {{ 1 + 1 }}');
});

it('runs a step that has nothing standing in its way', function () {
    $condition = app(EvaluateCondition::class);

    expect($condition->passes(null, runContext()))->toBeTrue()
        ->and($condition->passes([], runContext()))->toBeTrue();
});

it('compares as text and without minding capitals', function () {
    $condition = app(EvaluateCondition::class);

    expect($condition->passes([
        'path' => 'trigger.user.name',
        'operator' => WorkflowConditionOperator::Equals->value,
        'value' => 'pietje',
    ], runContext()))->toBeTrue();

    // The id arrives from a JSON column, where 7 may well be "7".
    expect($condition->passes([
        'path' => 'trigger.channel.id',
        'operator' => WorkflowConditionOperator::Equals->value,
        'value' => '7',
    ], runContext()))->toBeTrue();
});

it('answers the four ordinary questions about a piece of text', function () {
    $condition = app(EvaluateCondition::class);
    $context = runContext();

    $ask = fn (string $operator, string $value): bool => $condition->passes([
        'path' => 'trigger.message.text',
        'operator' => $operator,
        'value' => $value,
    ], $context);

    expect($ask('contains', 'storing'))->toBeTrue()
        ->and($ask('contains', 'factuur'))->toBeFalse()
        ->and($ask('not-contains', 'factuur'))->toBeTrue()
        ->and($ask('not-equals', 'iets anders'))->toBeTrue();
});

it('holds nothing against a needle nobody has typed yet', function () {
    expect(app(EvaluateCondition::class)->passes([
        'path' => 'trigger.message.text',
        'operator' => 'contains',
        'value' => '',
    ], runContext()))->toBeFalse();
});

it('calls a path that points at nothing empty', function () {
    $condition = app(EvaluateCondition::class);

    expect($condition->passes([
        'path' => 'trigger.user.bijnaam',
        'operator' => 'is-empty',
        'value' => '',
    ], runContext()))->toBeTrue()
        ->and($condition->passes([
            'path' => 'trigger.user.name',
            'operator' => 'is-not-empty',
            'value' => '',
        ], runContext()))->toBeTrue();
});

it('lets a condition compare two things out of the same run', function () {
    expect(app(EvaluateCondition::class)->passes([
        'path' => 'trigger.keyword',
        'operator' => 'equals',
        'value' => '{{ trigger.keyword }}',
    ], runContext()))->toBeTrue();
});

it('runs the step rather than silencing it when the comparison is one we do not know', function () {
    expect(app(EvaluateCondition::class)->passes([
        'path' => 'trigger.user.name',
        'operator' => 'is-ongeveer',
        'value' => 'Piet',
    ], runContext()))->toBeTrue();
});

it('hides the value box for the two questions that have no second side', function () {
    expect(WorkflowConditionOperator::IsEmpty->needsValue())->toBeFalse()
        ->and(WorkflowConditionOperator::IsNotEmpty->needsValue())->toBeFalse()
        ->and(WorkflowConditionOperator::Equals->needsValue())->toBeTrue();
});

it('names every comparison in both languages', function () {
    foreach (['nl', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (WorkflowConditionOperator::options() as $value => $label) {
            expect($label)->not->toContain('enums.', "{$value} heeft geen naam in {$locale}");
        }
    }
});

it('reads the rules of a condition together when it asks for all of them', function () {
    $condition = app(EvaluateCondition::class);

    $ask = fn (string $value): bool => $condition->passes([
        'match' => WorkflowConditionMatch::All->value,
        'otherwise' => WorkflowConditionOutcome::Skip->value,
        'rules' => [
            ['path' => 'trigger.channel.name', 'operator' => 'equals', 'value' => 'support'],
            ['path' => 'trigger.message.text', 'operator' => 'contains', 'value' => $value],
        ],
    ], runContext());

    expect($ask('storing'))->toBeTrue()
        // One rule short of the whole is not the whole.
        ->and($ask('factuur'))->toBeFalse();
});

it('lets one rule carry the condition when it asks for any of them', function () {
    $condition = app(EvaluateCondition::class);

    expect($condition->passes([
        'match' => WorkflowConditionMatch::Any->value,
        'rules' => [
            ['path' => 'trigger.channel.name', 'operator' => 'equals', 'value' => 'facturatie'],
            ['path' => 'trigger.message.text', 'operator' => 'contains', 'value' => 'storing'],
        ],
    ], runContext()))->toBeTrue();

    expect($condition->passes([
        'match' => WorkflowConditionMatch::Any->value,
        'rules' => [
            ['path' => 'trigger.channel.name', 'operator' => 'equals', 'value' => 'facturatie'],
            ['path' => 'trigger.message.text', 'operator' => 'contains', 'value' => 'factuur'],
        ],
    ], runContext()))->toBeFalse();
});

it('runs a step whose last rule has been taken out', function () {
    // What the builder leaves behind when somebody empties a condition rather
    // than removing it. Silencing the step for that would be a step that stops
    // working for a reason nobody can see on the screen.
    expect(app(EvaluateCondition::class)->passes([
        'match' => 'all',
        'otherwise' => 'stop',
        'rules' => [],
    ], runContext()))->toBeTrue();
});

it('still reads a condition that was written before rules were a list', function () {
    expect(app(EvaluateCondition::class)->passes([
        'path' => 'trigger.keyword',
        'operator' => 'equals',
        'value' => 'storing',
    ], runContext()))->toBeTrue();
});

it('takes a condition that does not say what it guards for one about its own step', function () {
    $condition = app(EvaluateCondition::class);

    expect($condition->outcome(null))->toBe(WorkflowConditionOutcome::Skip)
        ->and($condition->outcome(['path' => 'trigger.keyword', 'operator' => 'equals', 'value' => 'x']))
        ->toBe(WorkflowConditionOutcome::Skip)
        ->and($condition->outcome(['otherwise' => 'stop', 'rules' => []]))
        ->toBe(WorkflowConditionOutcome::Stop);
});

it('names every way of reading and answering a condition in both languages', function () {
    foreach (['nl', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([...WorkflowConditionMatch::options(), ...WorkflowConditionOutcome::options()] as $value => $label) {
            expect($label)->not->toContain('enums.', "{$value} heeft geen naam in {$locale}");
        }
    }
});
