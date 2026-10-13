<?php

namespace App\Domain;

/**
 * A single has exactly one selection; an accumulator has two or more.
 */
enum BetType: string
{
    case Single = 'single';
    case Accumulator = 'accumulator';

    /**
     * The type a bet with this many selections has.
     */
    public static function forSelectionCount(int $count): self
    {
        return $count > 1 ? self::Accumulator : self::Single;
    }
}
