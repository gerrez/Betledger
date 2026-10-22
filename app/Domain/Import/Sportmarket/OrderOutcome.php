<?php

namespace App\Domain\Import\Sportmarket;

enum OrderOutcome: string
{
    /** The order becomes a bet. */
    case Import = 'import';

    /** Nothing to import (yet): every fill failed, it isn't settled, or it was imported before. */
    case Skip = 'skip';

    /** The order can't be imported as it is, e.g. it is in another currency. */
    case Error = 'error';
}
