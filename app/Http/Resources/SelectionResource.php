<?php

namespace App\Http\Resources;

use App\Domain\Odds;
use App\Models\Selection;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One selection (leg) of a bet as the API returns it.
 *
 * @mixin Selection
 */
class SelectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'sport' => $this->sport->name,
            'competition' => $this->competition?->name,
            'market' => $this->market?->name,
            'teams' => $this->teams->map(fn (Team $team): string => $team->name)->values()->all(),
            'event_name' => $this->event_name,
            'event_starts_at' => $this->event_starts_at?->toIso8601ZuluString(),
            'pick' => $this->pick,
            'odds' => Odds::format($this->odds),
            'closing_odds' => $this->closing_odds === null ? null : Odds::format($this->closing_odds),
            'result' => $this->result?->value,
        ];
    }
}
