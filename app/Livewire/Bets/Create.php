<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\BetData;
use App\Domain\Bets\RecordBet;
use App\Domain\Bets\SelectionData;
use App\Domain\Decimal;
use App\Domain\ExchangeRate;
use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\SettlementCalculator;
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
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The New bet form (singles). Sport, competition, teams, market, tipster and tags are
 * typed as names; new names are added to the user's lists when the bet is saved.
 */
#[Title('New bet')]
class Create extends Component
{
    private const DATETIME_LOCAL = 'Y-m-d\TH:i';

    public string $bookmaker_id = '';

    public string $placed_at = '';

    public string $stake = '';

    public string $exchange_rate = '';

    public bool $is_free_bet = false;

    public string $tipster = '';

    /** @var list<string> */
    public array $tags = [];

    /** A tag being typed, added to `tags` with Enter or "Add". */
    public string $tag_name = '';

    public string $notes = '';

    public bool $showDetails = false;

    /**
     * The bet's selections; a single has one. `team_name` is a team being typed, added
     * to `teams` with Enter or "Add".
     *
     * @var list<array{sport: string, competition: string, market: string, teams: list<string>, team_name: string, event_name: string, event_starts_at: string, pick: string, odds: string, closing_odds: string}>
     */
    public array $selections = [];

    /**
     * Start from the bookmaker and sport of the user's latest bet, placed now.
     */
    public function mount(): void
    {
        $user = $this->user();
        $latestBet = $user->bets()->with('selections.sport')->latest('placed_at')->latest('id')->first();
        $bookmakers = $this->activeBookmakers();

        $bookmaker = $bookmakers->firstWhere('id', $latestBet?->bookmaker_id) ?? $bookmakers->first();

        $this->placed_at = now()->format(self::DATETIME_LOCAL);
        $this->selectBookmaker($bookmaker);
        $this->selections = [$this->emptySelection($latestBet?->selections->first()?->sport->name ?? '')];
    }

    /**
     * A new bookmaker brings its own currency and rate.
     */
    public function updatedBookmakerId(): void
    {
        $this->exchange_rate = $this->activeBookmakers()->firstWhere('id', (int) $this->bookmaker_id)?->formattedExchangeRate() ?? '';
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

    public function save(RecordBet $recordBet): void
    {
        $this->addTag();

        foreach (array_keys($this->selections) as $index) {
            $this->addTeam($index);
            $this->selections[$index]['event_name'] = trim($this->selections[$index]['event_name']) ?: $this->eventNameFor($this->selections[$index]['teams']);
        }

        $this->normalizeNumbers();

        try {
            $this->validate($this->rules(), $this->messages());
        } catch (ValidationException $exception) {
            $this->showDetails = $this->showDetails || $this->hasDetailErrors($exception);

            throw $exception;
        }

        $recordBet->handle($this->user(), $this->betData());

        session()->flash('status', 'Bet saved.');

        $this->redirectRoute('bets.index', navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $bookmaker = $this->activeBookmakers()->firstWhere('id', (int) $this->bookmaker_id);
        $sports = $user->sports()->orderBy('name')->get();

        return view('livewire.bets.create', [
            'bookmakers' => $this->activeBookmakers(),
            'bookmaker' => $bookmaker,
            'baseCurrency' => $user->base_currency,
            'isBaseCurrency' => $bookmaker?->currency === $user->base_currency,
            'stakeInBaseCurrency' => $this->stakeInBaseCurrency($bookmaker),
            'preview' => $this->preview(),
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
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $odds = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Odds::isValidSelectionOdds($value)) {
                $fail('Enter odds above 1, like 1.95 (up to 3 decimals).');
            }
        };

        return [
            'bookmaker_id' => [
                'required',
                Rule::exists('bookmakers', 'id')->where('user_id', $this->user()->id)->where('is_active', true),
            ],
            'placed_at' => ['required', 'date'],
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
            'selections' => ['required', 'array', 'size:1'],
            'selections.*.sport' => ['required', 'string', 'max:255'],
            'selections.*.competition' => ['nullable', 'string', 'max:255'],
            'selections.*.market' => ['nullable', 'string', 'max:255'],
            'selections.*.teams' => ['array'],
            'selections.*.teams.*' => ['string', 'max:255'],
            'selections.*.event_name' => ['required', 'string', 'max:255'],
            'selections.*.event_starts_at' => ['nullable', 'date'],
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
            'selections.*.sport.required' => 'Enter the sport.',
            'selections.*.event_name.required' => 'Enter the event, or add the teams taking part.',
            'selections.*.pick.required' => 'Enter what you backed.',
            'selections.*.odds.required' => 'Enter the odds.',
        ];
    }

    /**
     * Whether a field under "More details" failed, so the details must be shown.
     */
    private function hasDetailErrors(ValidationException $exception): bool
    {
        foreach (array_keys($exception->errors()) as $key) {
            if (preg_match('/^(tipster|tags|notes|exchange_rate|selections\.\d+\.(competition|market|closing_odds))(\.|$)/', $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim the typed numbers and accept a decimal comma ("1,95").
     */
    private function normalizeNumbers(): void
    {
        $this->stake = Decimal::fromInput($this->stake);
        $this->exchange_rate = Decimal::fromInput($this->exchange_rate);

        if ($this->isBaseCurrencyBookmaker()) {
            $this->exchange_rate = ExchangeRate::SAME_CURRENCY;
        }

        foreach (array_keys($this->selections) as $index) {
            $this->selections[$index]['odds'] = Decimal::fromInput($this->selections[$index]['odds']);
            $this->selections[$index]['closing_odds'] = Decimal::fromInput($this->selections[$index]['closing_odds']);
        }
    }

    private function betData(): BetData
    {
        return new BetData(
            bookmakerId: (int) $this->bookmaker_id,
            placedAt: Carbon::parse($this->placed_at),
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
                eventStartsAt: $selection['event_starts_at'] === '' ? null : Carbon::parse($selection['event_starts_at']),
                pick: trim($selection['pick']),
                odds: $selection['odds'],
                closingOdds: $this->nullIfBlank($selection['closing_odds']),
            ), $this->selections),
        );
    }

    /**
     * Payout and profit if every selection wins, or null until stake and odds are valid.
     *
     * @return array{payout: string, profit: string}|null
     */
    private function preview(): ?array
    {
        $stake = Decimal::fromInput($this->stake);
        $odds = array_map(fn (array $selection): string => Decimal::fromInput($selection['odds']), $this->selections);
        $bookmaker = $this->activeBookmakers()->firstWhere('id', (int) $this->bookmaker_id);

        if ($bookmaker === null || ! Money::isValidStake($stake) || $odds === [] || in_array(false, array_map(Odds::isValidSelectionOdds(...), $odds), true)) {
            return null;
        }

        $calculator = new SettlementCalculator;
        $stakeMinorUnits = Money::toMinorUnits($stake);
        $payout = $calculator->payout($stakeMinorUnits, Odds::total($odds), $this->is_free_bet);

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

    private function selectBookmaker(?Bookmaker $bookmaker): void
    {
        $this->bookmaker_id = $bookmaker === null ? '' : (string) $bookmaker->id;
        $this->exchange_rate = $bookmaker?->formattedExchangeRate() ?? '';
    }

    private function isBaseCurrencyBookmaker(): bool
    {
        return $this->activeBookmakers()->firstWhere('id', (int) $this->bookmaker_id)?->currency === $this->user()->base_currency;
    }

    /**
     * @return array{sport: string, competition: string, market: string, teams: list<string>, team_name: string, event_name: string, event_starts_at: string, pick: string, odds: string, closing_odds: string}
     */
    private function emptySelection(string $sport): array
    {
        return [
            'sport' => $sport,
            'competition' => '',
            'market' => '',
            'teams' => [],
            'team_name' => '',
            'event_name' => '',
            'event_starts_at' => '',
            'pick' => '',
            'odds' => '',
            'closing_odds' => '',
        ];
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The bookmakers a bet can be placed at: the user's active ones.
     *
     * @return Collection<int, Bookmaker>
     */
    private function activeBookmakers(): Collection
    {
        return once(fn () => $this->user()->bookmakers()->where('is_active', true)->orderBy('name')->get());
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
