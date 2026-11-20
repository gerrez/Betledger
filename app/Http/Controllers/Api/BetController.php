<?php

namespace App\Http\Controllers\Api;

use App\Domain\Bets\RecordBet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBetRequest;
use App\Http\Resources\BetResource;
use Illuminate\Http\JsonResponse;

class BetController extends Controller
{
    /**
     * Record a new bet for the token's user, as the New bet form does.
     */
    public function store(StoreBetRequest $request, RecordBet $recordBet): JsonResponse
    {
        $bet = $recordBet->handle($request->owner(), $request->betData());

        return BetResource::make($bet->load(['bookmaker', 'tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams']))
            ->response()
            ->setStatusCode(201);
    }
}
