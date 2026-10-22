<?php

namespace App\Domain\Import\Sportmarket;

use RuntimeException;

/**
 * One order can't be imported as it is. SportmarketImport reports it as an error for
 * that order and carries on with the others; the message is meant for the user.
 *
 * @internal
 */
final class InvalidOrder extends RuntimeException {}
