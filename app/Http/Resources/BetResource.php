<?php

namespace App\Http\Resources;

use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Odds;
use App\Models\Bet;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bet as the API returns it. Amounts are decimal strings in the bet's currency
 * ("25.50"), odds and rates decimal strings, dates ISO 8601 in UTC.
 *
 * @mixin Bet
 */
class BetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('bets.show', $this->resource),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'bookmaker' => [
                'id' => $this->bookmaker->id,
                'name' => $this->bookmaker->name,
            ],
            'placed_at' => $this->placed_at->toIso8601ZuluString(),
            'currency' => $this->currency->value,
            'stake' => Money::fromMinorUnits($this->stake),
            'exchange_rate' => Decimal::normalize($this->exchange_rate),
            'is_free_bet' => $this->is_free_bet,
            'total_odds' => Odds::format($this->total_odds),
            'probability' => $this->probability === null ? null : Decimal::normalize($this->probability),
            'bet_value' => $this->bet_value === null ? null : Decimal::normalize($this->bet_value),
            'tipster' => $this->tipster?->name,
            'tags' => $this->tags->map(fn (Tag $tag): string => $tag->name)->values()->all(),
            'notes' => $this->notes,
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
            'payout' => $this->payout === null ? null : Money::fromMinorUnits($this->payout),
            'profit' => $this->profit === null ? null : Money::fromMinorUnits($this->profit),
            'selections' => SelectionResource::collection($this->selections),
        ];
    }
}
