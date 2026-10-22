<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\BetData;
use App\Domain\Bets\SelectionData;
use App\Domain\Decimal;
use App\Domain\ExchangeRate;
use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\SettlementCalculator;
use App\Domain\TimeOfDay;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Sport;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The bet form shared by New bet and Edit bet: singles and accumulators. Sport,
 * competition, teams, market, tipster and tags are typed as names; new names are added
 * to the user's lists when the bet is saved.
 */
abstract class BetForm extends Component
{
    /** The most selections an accumulator may have. */
    public const MAX_SELECTIONS = 20;

    /** The format of the date fields, as `<input type="date">` sends it. */
    protected const DATE = 'Y-m-d';

    /** The format of the 24-hour time fields. */
    protected const TIME = 'H:i';

    public string $bookmaker_id = '';

    public string $placed_date = '';

    public string $placed_time = '';

    public string $stake = '';

    public string $exchange_rate = '';

    public bool $is_free_bet = false;

    public string $tipster = '';

    /** @var list<string> */
    public array $tags = [];

    /** A tag being typed, added to `tags` with Enter or "Add". */
    public string $tag_name = '';

    public string $notes = '';

    /** Whether the bet's own details (tipster, rate, tags, notes) are shown. */
    public bool $showDetails = false;

    /**
     * The bet's selections: a single has one, an accumulator two or more. `id` is the
     * saved selection being edited (null for a new one). `team_name` is a team being
     * typed, added to `teams` with Enter or "Add". `show_details` shows the selection's
     * own details (competition, market, closing odds).
     *
     * @var list<array{id: int|null, sport: string, competition: string, market: string, teams: list<string>, team_name: string, event_name: string, event_starts_date: string, event_starts_time: string, pick: string, odds: string, closing_odds: string, show_details: bool}>
     */
    public array $selections = [];

    /**
     * The bet being edited, or null for a new bet.
     */
    abstract protected function editedBet(): ?Bet;

    /**
     * A new bookmaker brings its own currency and rate.
     */
    public function updatedBookmakerId(): void
    {
        $this->exchange_rate = $this->availableBookmakers()->firstWhere('id', (int) $this->bookmaker_id)?->formattedExchangeRate() ?? '';
    }

    /**
     * Make the bet a single: only the first selection is kept.
     */
    public function useSingle(): void
    {
        $this->selections = [$this->selections[0] ?? $this->emptySelection('')];
    }

    /**
     * Make the bet an accumulator: a single gets a second selection.
     */
    public function useAccumulator(): void
    {
        if (count($this->selections) < 2) {
            $this->addSelection();
        }
    }

    /**
     * Add an empty selection in the sport of the last one.
     */
    public function addSelection(): void
    {
        if (count($this->selections) >= self::MAX_SELECTIONS) {
            return;
        }

        $this->selections[] = $this->emptySelection($this->selections[count($this->selections) - 1]['sport'] ?? '');
    }

    /**
     * Remove a selection from an accumulator, which keeps at least two; switch to Single
     * to go down to one.
     */
    public function removeSelection(int $index): void
    {
        if (! isset($this->selections[$index]) || count($this->selections) <= 2) {
            return;
        }

        unset($this->selections[$index]);
        $this->selections = array_values($this->selections);
    }

    public function toggleSelectionDetails(int $index): void
    {
        if (isset($this->selections[$index])) {
            $this->selections[$index]['show_details'] = ! $this->selections[$index]['show_details'];
        }
    }

    public function addTeam(int $index): void
    {
        if (! isset($this->selections[$index])) {
            return;
        }

        $name = trim($this->selections[$index]['team_name']);
        $teams = $this->selections[$index]['teams'];

        if ($name !== '' && ! in_array(mb_strtolower($name), array_map(mb_strtolower(...), $teams), true)) {
            $suggestions = $this->sportSuggestions($this->user()->sports()->get(), $this->selections[$index]['sport']);
            $this->changeTeams($index, [...$teams, $this->spelledAsSaved($name, $suggestions['teams'])]);
        }

        $this->selections[$index]['team_name'] = '';
    }

    public function removeTeam(int $index, int $teamIndex): void
    {
        if (! isset($this->selections[$index])) {
            return;
        }

        $teams = $this->selections[$index]['teams'];
        unset($teams[$teamIndex]);

        $this->changeTeams($index, array_values($teams));
    }

    public function addTag(): void
    {
        $name = trim($this->tag_name);

        if ($name !== '' && ! in_array(mb_strtolower($name), array_map(mb_strtolower(...), $this->tags), true)) {
            $this->tags[] = $this->spelledAsSaved($name, $this->user()->tags()->pluck('name')->all());
        }

        $this->tag_name = '';
    }

    public function removeTag(int $tagIndex): void
    {
        unset($this->tags[$tagIndex]);
        $this->tags = array_values($this->tags);
    }

    public function render(): View
    {
        $user = $this->user();
        $bookmaker = $this->availableBookmakers()->firstWhere('id', (int) $this->bookmaker_id);
        $sports = $user->sports()->orderBy('name')->get();
        $totalOdds = $this->totalOdds();

        return view('livewire.bets.bet-form', [
            'bet' => $this->editedBet(),
            'bookmakers' => $this->availableBookmakers(),
            'bookmaker' => $bookmaker,
            'baseCurrency' => $user->base_currency,
            'isBaseCurrency' => $bookmaker?->currency === $user->base_currency,
            'stakeInBaseCurrency' => $this->stakeInBaseCurrency($bookmaker),
            'isAccumulator' => count($this->selections) > 1,
            'canAddSelection' => count($this->selections) < self::MAX_SELECTIONS,
            'totalOdds' => $totalOdds === null ? null : Odds::format($totalOdds),
            'preview' => $this->preview(),
            'results' => $this->savedResults(),
            'settlement' => $this->savedSettlement(),
            'suggestions' => [
                'sports' => $sports->pluck('name'),
                'markets' => $user->markets()->orderBy('name')->pluck('name'),
                'tipsters' => $user->tipsters()->orderBy('name')->pluck('name'),
                'tags' => $user->tags()->orderBy('name')->pluck('name'),
            ],
            'sportSuggestions' => array_map(fn (array $selection): array => $this->sportSuggestions($sports, $selection['sport']), $this->selections),
        ]);
    }

    /**
     * Add what is still being typed, clean up the numbers and validate, then return the
     * bet as entered.
     *
     * @throws ValidationException
     */
    protected function validatedBetData(): BetData
    {
        $this->addTag();

        foreach (array_keys($this->selections) as $index) {
            $this->addTeam($index);
            $this->selections[$index]['event_name'] = trim($this->selections[$index]['event_name']) ?: $this->eventNameFor($this->selections[$index]['teams']);
        }

        $this->normalizeInput();

        try {
            $this->validate($this->rules(), $this->messages());
        } catch (ValidationException $exception) {
            $this->showDetailsWithErrors($exception);

            throw $exception;
        }

        return $this->betData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $odds = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Odds::isValidSelectionOdds($value)) {
                $fail('Enter odds above 1, like 1.95 (up to 3 decimals).');
            }
        };

        $time = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || TimeOfDay::fromInput($value) === null) {
                $fail('Enter a 24-hour time, like 18:30.');
            }
        };

        return [
            'bookmaker_id' => [
                'required',
                Rule::exists('bookmakers', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->editedBet()?->bookmaker_id)),
            ],
            'placed_date' => ['required', 'date_format:'.self::DATE],
            'placed_time' => ['required', 'string', $time],
            'stake' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! Money::isValidStake($value)) {
                        $fail('Enter the stake as an amount above 0, like 25 or 25.50.');
                    }
                },
            ],
            'exchange_rate' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! ExchangeRate::isValid($value)) {
                        $fail('Enter the rate as a number above 0, like 7.46 (up to 8 decimals).');
                    }
                },
            ],
            'is_free_bet' => ['boolean'],
            'tipster' => ['nullable', 'string', 'max:255'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'selections' => [
                'required',
                'array',
                'min:1',
                'max:'.self::MAX_SELECTIONS,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $total = $this->totalOdds();

                    if ($total !== null && ! Odds::isValidTotal($total)) {
                        $fail('The total odds can’t be above 9,999,999.999. Remove a selection.');
                    }
                },
            ],
            'selections.*.id' => ['nullable', 'integer', 'distinct', Rule::in($this->savedSelectionIds())],
            'selections.*.sport' => ['required', 'string', 'max:255'],
            'selections.*.competition' => ['nullable', 'string', 'max:255'],
            'selections.*.market' => ['nullable', 'string', 'max:255'],
            'selections.*.teams' => ['array'],
            'selections.*.teams.*' => ['string', 'max:255'],
            'selections.*.event_name' => ['required', 'string', 'max:255'],
            'selections.*.event_starts_date' => ['nullable', 'required_with:selections.*.event_starts_time', 'date_format:'.self::DATE],
            'selections.*.event_starts_time' => ['nullable', 'required_with:selections.*.event_starts_date', 'string', $time],
            'selections.*.pick' => ['required', 'string', 'max:255'],
            'selections.*.odds' => ['required', 'string', $odds],
            'selections.*.closing_odds' => ['nullable', 'string', $odds],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'bookmaker_id.required' => 'Choose the bookmaker.',
            'bookmaker_id.exists' => 'Choose one of your bookmakers.',
            'placed_date.required' => 'Enter the date.',
            'placed_date.date_format' => 'Enter a valid date.',
            'placed_time.required' => 'Enter the time.',
            'selections.max' => 'An accumulator can have at most '.self::MAX_SELECTIONS.' selections.',
            'selections.*.id' => 'This selection is not part of the bet. Reload the page and try again.',
            'selections.*.sport.required' => 'Enter the sport.',
            'selections.*.event_name.required' => 'Enter the event, or add the teams taking part.',
            'selections.*.pick.required' => 'Enter what you backed.',
            'selections.*.odds.required' => 'Enter the odds.',
            'selections.*.event_starts_date.required_with' => 'Enter the date too, or clear the time.',
            'selections.*.event_starts_date.date_format' => 'Enter a valid date.',
            'selections.*.event_starts_time.required_with' => 'Enter the time too, or clear the date.',
        ];
    }

    /**
     * Show the details, the bet's or a selection's, that hold a field that failed.
     */
    private function showDetailsWithErrors(ValidationException $exception): void
    {
        foreach (array_keys($exception->errors()) as $key) {
            if (preg_match('/^(tipster|tags|notes|exchange_rate)(\.|$)/', $key) === 1) {
                $this->showDetails = true;
            }

            if (preg_match('/^selections\.(\d+)\.(competition|market|closing_odds)(\.|$)/', $key, $matches) === 1 && isset($this->selections[(int) $matches[1]])) {
                $this->selections[(int) $matches[1]]['show_details'] = true;
            }
        }
    }

    /**
     * Trim the typed numbers and accept a decimal comma ("1,95"); write valid times as
     * "HH:MM" ("1830" → "18:30").
     */
    private function normalizeInput(): void
    {
        $this->placed_time = TimeOfDay::fromInput($this->placed_time) ?? trim($this->placed_time);
        $this->stake = Decimal::fromInput($this->stake);
        $this->exchange_rate = Decimal::fromInput($this->exchange_rate);

        if ($this->isBaseCurrencyBookmaker()) {
            $this->exchange_rate = ExchangeRate::SAME_CURRENCY;
        }

        foreach (array_keys($this->selections) as $index) {
            $this->selections[$index]['odds'] = Decimal::fromInput($this->selections[$index]['odds']);
            $this->selections[$index]['closing_odds'] = Decimal::fromInput($this->selections[$index]['closing_odds']);
            $this->selections[$index]['event_starts_time'] = TimeOfDay::fromInput($this->selections[$index]['event_starts_time']) ?? trim($this->selections[$index]['event_starts_time']);
        }
    }

    private function betData(): BetData
    {
        return new BetData(
            bookmakerId: (int) $this->bookmaker_id,
            placedAt: Carbon::parse($this->placed_date.' '.$this->placed_time),
            stake: Money::toMinorUnits($this->stake),
            exchangeRate: $this->exchange_rate,
            isFreeBet: $this->is_free_bet,
            tipster: $this->nullIfBlank($this->tipster),
            tags: $this->tags,
            notes: $this->nullIfBlank($this->notes),
            selections: array_map(fn (array $selection): SelectionData => new SelectionData(
                sport: trim($selection['sport']),
                competition: $this->nullIfBlank($selection['competition']),
                market: $this->nullIfBlank($selection['market']),
                teams: $selection['teams'],
                eventName: $selection['event_name'],
                eventStartsAt: $selection['event_starts_date'] === '' ? null : Carbon::parse($selection['event_starts_date'].' '.$selection['event_starts_time']),
                pick: trim($selection['pick']),
                odds: $selection['odds'],
                closingOdds: $this->nullIfBlank($selection['closing_odds']),
                id: $selection['id'],
            ), $this->selections),
        );
    }

    /**
     * The product of the selection odds, or null until every selection has valid odds.
     */
    private function totalOdds(): ?string
    {
        $odds = array_map(fn (array $selection): string => Decimal::fromInput($selection['odds']), $this->selections);

        if ($odds === [] || in_array(false, array_map(Odds::isValidSelectionOdds(...), $odds), true)) {
            return null;
        }

        return Odds::total($odds);
    }

    /**
     * Payout and profit if every selection wins, or null until stake and odds are valid.
     *
     * @return array{payout: string, profit: string}|null
     */
    private function preview(): ?array
    {
        $stake = Decimal::fromInput($this->stake);
        $totalOdds = $this->totalOdds();
        $bookmaker = $this->availableBookmakers()->firstWhere('id', (int) $this->bookmaker_id);

        if ($bookmaker === null || ! Money::isValidStake($stake) || $totalOdds === null || ! Odds::isValidTotal($totalOdds)) {
            return null;
        }

        $calculator = new SettlementCalculator;
        $stakeMinorUnits = Money::toMinorUnits($stake);
        $payout = $calculator->payout($stakeMinorUnits, $totalOdds, $this->is_free_bet);

        return [
            'payout' => Money::format($payout, $bookmaker->currency),
            'profit' => Money::format($calculator->profit($stakeMinorUnits, $payout, $this->is_free_bet), $bookmaker->currency, signed: true),
        ];
    }

    /**
     * The stake in the base currency at the entered rate, for bookmakers in another
     * currency, or null.
     */
    private function stakeInBaseCurrency(?Bookmaker $bookmaker): ?string
    {
        $stake = Decimal::fromInput($this->stake);
        $rate = Decimal::fromInput($this->exchange_rate);

        if ($bookmaker === null || $this->isBaseCurrencyBookmaker() || ! Money::isValidStake($stake) || ! ExchangeRate::isValid($rate)) {
            return null;
        }

        return Money::format(Money::convert(Money::toMinorUnits($stake), $rate), $this->user()->base_currency);
    }

    /**
     * The edited bet's payout and profit while it is settled, or null.
     *
     * @return array{payout: string, profit: string, isManual: bool}|null
     */
    private function savedSettlement(): ?array
    {
        $bet = $this->editedBet();

        if ($bet === null || ! $bet->isSettled() || $bet->payout === null || $bet->profit === null) {
            return null;
        }

        return [
            'payout' => Money::format($bet->payout, $bet->currency),
            'profit' => Money::format($bet->profit, $bet->currency, signed: true),
            'isManual' => $bet->payout_is_manual,
        ];
    }

    /**
     * For each selection in the form, the saved result it keeps ("Won", …), or null for
     * a pending or new selection.
     *
     * @return list<string|null>
     */
    private function savedResults(): array
    {
        $saved = $this->editedBet()?->selections()->get()->keyBy('id');

        return array_map(
            fn (array $selection): ?string => $saved?->get($selection['id'] ?? 0)?->result?->label(),
            $this->selections,
        );
    }

    /**
     * The ids of the edited bet's saved selections: the only ids a selection in the
     * form may have.
     *
     * @return array<int, mixed>
     */
    private function savedSelectionIds(): array
    {
        return $this->editedBet()?->selections()->get()->modelKeys() ?? [];
    }

    /**
     * Competitions and teams the user has in the sport typed for a selection.
     *
     * @param  Collection<int, Sport>  $sports
     * @return array{competitions: list<string>, teams: list<string>}
     */
    private function sportSuggestions(Collection $sports, string $sportName): array
    {
        $sport = $sports->first(fn (Sport $sport): bool => mb_strtolower($sport->name) === mb_strtolower(trim($sportName)));

        if ($sport === null) {
            return ['competitions' => [], 'teams' => []];
        }

        return [
            'competitions' => array_values($sport->competitions()->orderBy('name')->pluck('name')->all()),
            'teams' => array_values($sport->teams()->orderBy('name')->pluck('name')->all()),
        ];
    }

    /**
     * Update a selection's teams, and its event name too while that is still the one
     * made from the teams (or empty).
     *
     * @param  list<string>  $teams
     */
    private function changeTeams(int $index, array $teams): void
    {
        $selection = $this->selections[$index];

        if (in_array(trim($selection['event_name']), ['', $this->eventNameFor($selection['teams'])], true)) {
            $this->selections[$index]['event_name'] = $this->eventNameFor($teams);
        }

        $this->selections[$index]['teams'] = $teams;
    }

    /**
     * The name as the user already saved it ("chelsea" → "Chelsea"), or as typed.
     *
     * @param  array<string>  $savedNames
     */
    private function spelledAsSaved(string $name, array $savedNames): string
    {
        foreach ($savedNames as $savedName) {
            if (mb_strtolower($savedName) === mb_strtolower($name)) {
                return $savedName;
            }
        }

        return $name;
    }

    /**
     * "Arsenal v Chelsea" from the teams taking part.
     *
     * @param  list<string>  $teams
     */
    private function eventNameFor(array $teams): string
    {
        return implode(' v ', $teams);
    }

    protected function selectBookmaker(?Bookmaker $bookmaker): void
    {
        $this->bookmaker_id = $bookmaker === null ? '' : (string) $bookmaker->id;
        $this->exchange_rate = $bookmaker?->formattedExchangeRate() ?? '';
    }

    private function isBaseCurrencyBookmaker(): bool
    {
        return $this->availableBookmakers()->firstWhere('id', (int) $this->bookmaker_id)?->currency === $this->user()->base_currency;
    }

    /**
     * @return array{id: int|null, sport: string, competition: string, market: string, teams: list<string>, team_name: string, event_name: string, event_starts_date: string, event_starts_time: string, pick: string, odds: string, closing_odds: string, show_details: bool}
     */
    protected function emptySelection(string $sport): array
    {
        return [
            'id' => null,
            'sport' => $sport,
            'competition' => '',
            'market' => '',
            'teams' => [],
            'team_name' => '',
            'event_name' => '',
            'event_starts_date' => '',
            'event_starts_time' => '',
            'pick' => '',
            'odds' => '',
            'closing_odds' => '',
            'show_details' => false,
        ];
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The bookmakers a bet can be placed at: the user's active ones, plus the edited
     * bet's own bookmaker even when it has been deactivated since.
     *
     * @return Collection<int, Bookmaker>
     */
    protected function availableBookmakers(): Collection
    {
        return once(fn () => $this->user()->bookmakers()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $this->editedBet()?->bookmaker_id))
            ->orderBy('name')
            ->get());
    }

    protected function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
