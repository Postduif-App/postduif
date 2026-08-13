<?php

use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionOperatorGroup;
use App\Workflows\EvaluateCondition;

/**
 * One rule, asked of one context.
 *
 * @param  array<string, mixed>  $context
 */
function asks(string $path, WorkflowConditionOperator $operator, string $value, array $context): bool
{
    return app(EvaluateCondition::class)->passes([
        'match' => 'all',
        'rules' => [['path' => $path, 'operator' => $operator->value, 'value' => $value]],
    ], $context);
}

/*
 * The five conditions that could not be written before these operators
 * existed. They are the reason for the story, so they are the first thing the
 * test says — a regression here is not "an operator misbehaves", it is a
 * workflow somebody built going quiet.
 */
it('answers the questions that six string operators could not', function () {
    $contract = ['trigger' => ['contract' => [
        'days_until_expiry' => 2,
        'signed_count' => 3,
        'remaining' => 1,
    ]]];

    expect(asks('trigger.contract.days_until_expiry', WorkflowConditionOperator::LessOrEqual, '3', $contract))->toBeTrue()
        ->and(asks('trigger.contract.days_until_expiry', WorkflowConditionOperator::LessOrEqual, '1', $contract))->toBeFalse()

        // Against another path, which is what makes "meer dan de helft" say
        // itself: more have signed than are still to sign.
        ->and(asks('trigger.contract.signed_count', WorkflowConditionOperator::GreaterThan, '{{ trigger.contract.remaining }}', $contract))->toBeTrue();

    $ticket = ['trigger' => ['ticket' => ['priority' => 'urgent', 'hours_open' => 30]]];

    expect(asks('trigger.ticket.priority', WorkflowConditionOperator::IsOneOf, 'hoog, urgent', $ticket))->toBeTrue()
        ->and(asks('trigger.ticket.priority', WorkflowConditionOperator::IsOneOf, 'laag, normaal', $ticket))->toBeFalse()
        ->and(asks('trigger.ticket.hours_open', WorkflowConditionOperator::GreaterThan, '24', $ticket))->toBeTrue();

    // The external signer: no account behind them, so nothing under user_id.
    $signer = ['trigger' => ['signer' => ['user_id' => null, 'name' => 'Jan de Vries']]];

    expect(asks('trigger.signer.user_id', WorkflowConditionOperator::IsEmpty, '', $signer))->toBeTrue();
});

/**
 * Numbers compared as numbers is the whole point: as text, "9" comes after
 * "10", and a workflow written to fire above ten would fire on nine.
 */
it('ranks two numbers as numbers and everything else as text', function () {
    $numbers = ['trigger' => ['count' => 9]];

    expect(asks('trigger.count', WorkflowConditionOperator::GreaterThan, '10', $numbers))->toBeFalse()
        ->and(asks('trigger.count', WorkflowConditionOperator::LessThan, '10', $numbers))->toBeTrue()
        ->and(asks('trigger.count', WorkflowConditionOperator::GreaterOrEqual, '9', $numbers))->toBeTrue()
        ->and(asks('trigger.count', WorkflowConditionOperator::LessOrEqual, '9', $numbers))->toBeTrue();

    // A decimal that arrived as a string still ranks as a quantity: the shift
    // trigger hands its hours over as 7.5.
    $hours = ['trigger' => ['shift' => ['hours' => '7.5']]];

    expect(asks('trigger.shift.hours', WorkflowConditionOperator::GreaterThan, '7', $hours))->toBeTrue()
        ->and(asks('trigger.shift.hours', WorkflowConditionOperator::GreaterThan, '8', $hours))->toBeFalse();

    $words = ['trigger' => ['fruit' => 'banaan']];

    expect(asks('trigger.fruit', WorkflowConditionOperator::GreaterThan, 'appel', $words))->toBeTrue();
});

/** Nothing to rank against is an answer, and the answer is no. */
it('refuses to rank against a path that holds nothing', function () {
    $nothing = ['trigger' => ['count' => null]];

    expect(asks('trigger.count', WorkflowConditionOperator::GreaterThan, '0', $nothing))->toBeFalse()
        ->and(asks('trigger.count', WorkflowConditionOperator::LessThan, '0', $nothing))->toBeFalse()
        ->and(asks('trigger.count', WorkflowConditionOperator::GreaterOrEqual, '0', $nothing))->toBeFalse();
});

it('compares two moments as moments, however they were written', function () {
    $context = ['trigger' => ['contract' => [
        'expires_at' => '2026-08-20T09:00:00+02:00',
        'sent_at' => '2026-08-13 09:00:00',
    ]]];

    expect(asks('trigger.contract.sent_at', WorkflowConditionOperator::Before, '2026-08-20', $context))->toBeTrue()
        ->and(asks('trigger.contract.expires_at', WorkflowConditionOperator::After, '2026-08-14', $context))->toBeTrue()
        ->and(asks('trigger.contract.expires_at', WorkflowConditionOperator::Before, '{{ trigger.contract.sent_at }}', $context))->toBeFalse();
});

/**
 * Carbon reads "3" as the third of this month and an empty string as now,
 * which would make a mistyped condition compare against a date nobody wrote —
 * quietly, and differently every month.
 */
it('does not read a bare number or an empty value as a date', function () {
    $context = ['trigger' => ['contract' => ['expires_at' => '2026-08-20', 'days' => 3, 'nothing' => null]]];

    expect(asks('trigger.contract.days', WorkflowConditionOperator::Before, '2026-08-20', $context))->toBeFalse()
        ->and(asks('trigger.contract.expires_at', WorkflowConditionOperator::After, '3', $context))->toBeFalse()
        ->and(asks('trigger.contract.nothing', WorkflowConditionOperator::Before, '2026-08-20', $context))->toBeFalse()
        ->and(asks('trigger.contract.expires_at', WorkflowConditionOperator::Before, 'ergens volgende week', $context))->toBeFalse();
});

it('reads a yes as a yes, whether it arrived as a boolean or as a word', function () {
    $context = ['trigger' => [
        'contract' => ['render_failed' => true, 'is_signed' => false],
        'webhook' => ['paid' => 'true', 'refunded' => 'no', 'count' => 5],
    ]];

    expect(asks('trigger.contract.render_failed', WorkflowConditionOperator::IsTrue, '', $context))->toBeTrue()
        ->and(asks('trigger.contract.is_signed', WorkflowConditionOperator::IsTrue, '', $context))->toBeFalse()
        ->and(asks('trigger.contract.is_signed', WorkflowConditionOperator::IsFalse, '', $context))->toBeTrue()
        ->and(asks('trigger.webhook.paid', WorkflowConditionOperator::IsTrue, '', $context))->toBeTrue()
        ->and(asks('trigger.webhook.refunded', WorkflowConditionOperator::IsFalse, '', $context))->toBeTrue()

        // A number is not a yes. Somebody who means "more than nought" has an
        // operator for that, and guessing here would make 0 the only false one.
        ->and(asks('trigger.webhook.count', WorkflowConditionOperator::IsTrue, '', $context))->toBeFalse()

        // A path that holds nothing is not true, which is what "is niet
        // aangevinkt" means for a box nobody ticked.
        ->and(asks('trigger.webhook.missing', WorkflowConditionOperator::IsFalse, '', $context))->toBeTrue();
});

it('matches the ends of a value, and a whole list of them', function () {
    $context = ['trigger' => ['signer' => ['email' => 'jan@klant.nl'], 'ref' => 'INV-2026-0042']];

    expect(asks('trigger.ref', WorkflowConditionOperator::StartsWith, 'INV-', $context))->toBeTrue()
        ->and(asks('trigger.signer.email', WorkflowConditionOperator::EndsWith, '@klant.nl', $context))->toBeTrue()
        ->and(asks('trigger.signer.email', WorkflowConditionOperator::EndsWith, '@ons.nl', $context))->toBeFalse()

        // Case and stray spaces are somebody typing, not somebody meaning
        // something else.
        ->and(asks('trigger.ref', WorkflowConditionOperator::StartsWith, ' inv- ', $context))->toBeTrue();
});

/**
 * The shape a rule has while it is being written: the operator chosen, the
 * value not yet typed. It must not switch the step off under somebody's hands
 * — the same reasoning "bevat" already followed for an empty needle.
 */
it('lets a half-written list rule alone', function () {
    $context = ['trigger' => ['ticket' => ['priority' => 'hoog']]];

    expect(asks('trigger.ticket.priority', WorkflowConditionOperator::IsOneOf, '', $context))->toBeFalse()
        ->and(asks('trigger.ticket.priority', WorkflowConditionOperator::IsNoneOf, '', $context))->toBeTrue()
        ->and(asks('trigger.ticket.priority', WorkflowConditionOperator::IsNoneOf, 'laag, normaal', $context))->toBeTrue()
        ->and(asks('trigger.ticket.priority', WorkflowConditionOperator::IsNoneOf, 'hoog', $context))->toBeFalse();
});

/**
 * The dropdown is drawn from this, so a gap here is an operator that either
 * cannot be picked or is picked with no label on it.
 */
it('offers every operator under exactly one heading', function () {
    $grouped = WorkflowConditionOperator::grouped();
    $offered = collect($grouped)->flatMap(fn (array $group): array => $group['operators']);

    expect($offered->pluck('value')->all())
        ->toEqualCanonicalizing(array_column(WorkflowConditionOperator::cases(), 'value'))
        ->and($offered->pluck('value')->duplicates())->toBeEmpty()
        ->and($grouped)->toHaveCount(count(WorkflowConditionOperatorGroup::cases()))
        ->and($offered->pluck('label')->filter())->toHaveCount($offered->count());

    // The four that compare against nothing, and nothing else.
    expect($offered->where('needsValue', false)->pluck('value')->all())
        ->toEqualCanonicalizing(['is-empty', 'is-not-empty', 'is-true', 'is-false']);
});
