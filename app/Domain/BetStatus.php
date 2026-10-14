<?php

namespace App\Domain;

/**
 * Open until the bet is settled (see SettlementCalculator::isSettled()); settling is
 * reversible, so a settled bet can go back to open.
 */
enum BetStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
}
