<?php

namespace App\Actions\Contracts;

use RuntimeException;

/**
 * A signature that will not be accepted.
 *
 * Its own type because every reason it carries is something the person in front
 * of the screen can act on: fill in the box you skipped, put down a signature,
 * or — in the one case nobody can act on — stop, because the document is not
 * the one you were sent.
 *
 * That last one is why this is an exception rather than a validation error. A
 * missing field is a form that is not finished; a document whose hash has moved
 * is a thing that should never have been possible, and it has to be loud.
 */
class SigningRefused extends RuntimeException {}
