<?php

namespace App\Livewire\Bets;

use App\Domain\BetStatus;

/**
 * The Open / Settled / All tabs of the bets list.
 */
enum BetListTab: string
{
    case Open = 'open';
    case Settled = 'settled';
    case All = 'all';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The status the tab shows; null for every bet.
     */
    public function status(): ?BetStatus
    {
        return match ($this) {
            self::Open => BetStatus::Open,
            self::Settled => BetStatus::Settled,
            self::All => null,
        };
    }

    /**
     * The orders the tab offers, its default first.
     *
     * @return non-empty-list<BetSort>
     */
    public function sorts(): array
    {
        return match ($this) {
            self::Open => [BetSort::StartsSoonest, BetSort::Newest, BetSort::Oldest],
            self::Settled => [BetSort::RecentlySettled, BetSort::Newest, BetSort::Oldest],
            self::All => [BetSort::Newest, BetSort::Oldest],
        };
    }
}
