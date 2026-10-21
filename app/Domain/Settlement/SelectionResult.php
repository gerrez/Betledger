<?php

namespace App\Domain\Settlement;

use App\Domain\Decimal;
use App\Domain\Odds;

enum SelectionResult: string
{
    case Won = 'won';
    case Lost = 'lost';
    case Void = 'void';
    case HalfWon = 'half_won';
    case HalfLost = 'half_lost';

    /**
     * The result as shown to the user.
     */
    public function label(): string
    {
        return match ($this) {
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Void => 'Void',
            self::HalfWon => '½ Won',
            self::HalfLost => '½ Lost',
        };
    }

    /**
     * What the selection multiplies the stake by: odds when won, 0 when lost, 1 when
     * void, (odds + 1) / 2 when half won and 0.5 when half lost.
     */
    public function factor(string $odds): string
    {
        Odds::assertValid($odds);

        return match ($this) {
            self::Won => $odds,
            self::Lost => '0',
            self::Void => '1',
            self::HalfWon => Decimal::divide(Decimal::add($odds, '1'), '2'),
            self::HalfLost => '0.5',
        };
    }
}
