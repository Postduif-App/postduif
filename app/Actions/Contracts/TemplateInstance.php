<?php

namespace App\Actions\Contracts;

use App\Models\Contract;

/**
 * What came out of a template, and how to talk about its boxes.
 *
 * The contract alone would do for everything the screens want. The map is here
 * for the caller that has values to put in the new boxes and only knows the old
 * ones: an API caller reads a template once, keeps the field ids it was given,
 * and sends them back with every contract it makes from it. Without this it
 * would have to guess which box on the copy is which, and the only guess
 * available is "the fields come out in the same order", which is true today
 * and is not a promise InstantiateTemplate ever made.
 *
 * A tiny object rather than a second return value or a property on the action:
 * an action that remembers what it did last is a shared thing pretending to be
 * a step, and would answer about the wrong contract the moment two calls
 * overlap on a queue.
 */
readonly class TemplateInstance
{
    /**
     * @param  array<int, int>  $fields  Template field id to the id of its copy.
     */
    public function __construct(
        public Contract $contract,
        public array $fields,
    ) {}
}
