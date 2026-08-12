<?php

namespace App\Actions\Contracts;

use RuntimeException;

/**
 * An uploaded PDF that will not be accepted as a contract.
 *
 * Its own type so the reason reaches the author verbatim. Every refusal here is
 * something a person can act on — pick another file, flatten it first, split it
 * up — which is exactly what a generic "er ging iets mis" takes away from them.
 *
 * The message is a translation key's worth of prose rather than a code, because
 * there are only a handful of these and each wants its own sentence: "hier zit
 * JavaScript in" and "dit zijn te veel pagina's" have nothing useful in common.
 */
class PdfRefused extends RuntimeException {}
