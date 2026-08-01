<?php

namespace App\Actions\Chat;

use RuntimeException;

/**
 * A scheduled message that may no longer be posted when its moment arrives.
 *
 * Its own type so the reason reaches the author verbatim: "je mocht niet meer
 * posten" is something they can act on, while every other failure is a bug they
 * cannot, and gets a generic sentence instead.
 */
class ScheduledMessageRefused extends RuntimeException {}
