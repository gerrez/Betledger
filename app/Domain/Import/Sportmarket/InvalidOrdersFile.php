<?php

namespace App\Domain\Import\Sportmarket;

use RuntimeException;

/**
 * The file can't be read as a Sportmarket orders export at all (as opposed to a single
 * order that can't be imported). The message is meant for the user.
 */
final class InvalidOrdersFile extends RuntimeException {}
