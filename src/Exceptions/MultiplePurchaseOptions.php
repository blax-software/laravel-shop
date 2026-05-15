<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Thrown when a single purchase path cannot be auto-selected because the
 * product exposes multiple options (e.g. several variants, several
 * default prices, several configurations) and the caller did not specify
 * which one to use.
 *
 * Typical resolution at the call site: surface the available options to
 * the user / API consumer and re-issue the request with an explicit
 * choice, rather than letting the package guess.
 */
class MultiplePurchaseOptions extends Exception
{
    public function __construct(
        string $message = 'Multiple purchase options are available — caller must pick one explicitly.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
