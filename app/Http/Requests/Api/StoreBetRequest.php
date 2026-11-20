<?php

namespace App\Http\Requests\Api;

use App\Domain\Bets\BetData;
use App\Domain\Bets\SelectionData;
use App\Domain\BetValue;
use App\Domain\Decimal;
use App\Domain\ExchangeRate;
use App\Domain\Money;
use App\Domain\Odds;
use App\Livewire\Bets\BetForm;
use App\Models\Bookmaker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new bet sent to the API: the same fields as the New bet form. Amounts, odds, rates
 * and percentages (probability, bet value) are decimal strings ("25.50", "1.95"; a JSON integer is accepted too, a JSON
 * float is not), dates are ISO 8601 date-times. Sport, competition, market, teams,
 * tipster and tags are names, matched to the user's own entries or added to their lists.
 */
class StoreBetRequest extends FormRequest
{
    /** The decimal fields of a bet and of each selection. */
    private const BET_DECIMALS = ['stake', 'exchange_rate', 'probability', 'bet_value'];

    private const SELECTION_DECIMALS = ['odds', 'closing_odds'];

    /**
     * An ISO 8601 date-time to the minute or second, with an optional offset:
     * "2026-11-19T18:30", "2026-11-19T18:30:00+01:00", "2026-11-19T17:30:00Z".
     */
    private const DATE_TIME = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * Any signed-in user may record bets for themselves; RecordBet only ever uses the
     * user's own bookmakers and lists.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * Accept whole numbers sent as JSON integers and a decimal comma ("1,95"), like the
     * form does; derive a missing event name from the teams ("Arsenal v Chelsea").
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (self::BET_DECIMALS as $key) {
            $input[$key] = $this->decimalInput($input[$key] ?? null);
        }

        if (is_array($input['selections'] ?? null)) {
            foreach ($input['selections'] as $index => $selection) {
                if (! is_array($selection)) {
                    continue;
                }

                foreach (self::SELECTION_DECIMALS as $key) {
                    $selection[$key] = $this->decimalInput($selection[$key] ?? null);
                }

                $teams = $selection['teams'] ?? null;

                if (($selection['event_name'] ?? null) === null && is_array($teams) && $teams !== [] && array_filter($teams, is_string(...)) === $teams) {
                    $selection['event_name'] = implode(' v ', $teams);
                }

                $input['selections'][$index] = $selection;
            }
        }

        $this->replace($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $odds = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Odds::isValidSelectionOdds($value)) {
                $fail('Enter odds above 1 as a string, like "1.95" (up to 3 decimals).');
            }
        };

        $dateTime = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || self::parseDateTime($value) === null) {
                $fail('Enter an ISO 8601 date and time, like "2026-11-19T18:30:00+01:00".');
            }
        };

        return [
            'bookmaker_id' => ['required_without:bookmaker', 'prohibits:bookmaker', 'nullable', 'integer'],
            'bookmaker' => ['required_without:bookmaker_id', 'nullable', 'string', 'max:255'],
            'placed_at' => ['nullable', 'string', $dateTime],
            'stake' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! Money::isValidStake($value)) {
                        $fail('Enter the stake as an amount above 0, like "25" or "25.50".');
                    }
                },
            ],
            'exchange_rate' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! ExchangeRate::isValid($value)) {
                        $fail('Enter the rate as a number above 0, like "7.46" (up to 8 decimals).');
                    }
                },
            ],
            'is_free_bet' => ['boolean'],
            'tipster' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'list'],
            'tags.*' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'probability' => [
                'nullable',
                'string',
                'prohibits:bet_value',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! BetValue::isValidProbability($value)) {
                        $fail('Enter the probability as a percentage above 0 and at most 100, like "55.5" (up to 2 decimals).');
                    }
                },
            ],
            'bet_value' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! BetValue::isValid($value)) {
                        $fail('Enter the bet value as a percentage above 0, like "104.5" (up to 2 decimals).');
                    }
                },
            ],
            'selections' => [
                'required',
                'list',
                'min:1',
                'max:'.BetForm::MAX_SELECTIONS,
                function (string $attribute, mixed $value, Closure $fail): void {
                    $total = $this->totalOdds();

                    if ($total !== null && ! Odds::isValidTotal($total)) {
                        $fail('The total odds can’t be above 9,999,999.999.');
                    }
                },
            ],
            'selections.*' => ['array'],
            'selections.*.sport' => ['required', 'string', 'max:255'],
            'selections.*.competition' => ['nullable', 'string', 'max:255'],
            'selections.*.market' => ['nullable', 'string', 'max:255'],
            'selections.*.teams' => ['nullable', 'list'],
            'selections.*.teams.*' => ['required', 'string', 'max:255'],
            'selections.*.event_name' => ['required', 'string', 'max:255'],
            'selections.*.event_starts_at' => ['nullable', 'string', $dateTime],
            'selections.*.pick' => ['required', 'string', 'max:255'],
            'selections.*.odds' => ['required', 'string', $odds],
            'selections.*.closing_odds' => ['nullable', 'string', $odds],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bookmaker_id.required_without' => 'Send the bookmaker, by name (`bookmaker`) or id (`bookmaker_id`).',
            'bookmaker.required_without' => 'Send the bookmaker, by name (`bookmaker`) or id (`bookmaker_id`).',
            'bookmaker_id.prohibits' => 'Send the bookmaker by name or by id, not both.',
            'stake.required' => 'Enter the stake.',
            'stake.string' => 'Send the stake as a string, like "25.50".',
            'exchange_rate.string' => 'Send the rate as a string, like "7.46".',
            'probability.string' => 'Send the probability as a string, like "55.5".',
            'probability.prohibits' => 'Send the probability or the bet value, not both: the bet value is calculated from the probability.',
            'bet_value.string' => 'Send the bet value as a string, like "104.5".',
            'selections.required' => 'Add at least one selection.',
            'selections.max' => 'An accumulator can have at most '.BetForm::MAX_SELECTIONS.' selections.',
            'selections.*.sport.required' => 'Enter the sport.',
            'selections.*.event_name.required' => 'Enter the event, or add the teams taking part.',
            'selections.*.pick.required' => 'Enter what you backed.',
            'selections.*.odds.required' => 'Enter the odds.',
            'selections.*.odds.string' => 'Send the odds as a string, like "1.95".',
            'selections.*.closing_odds.string' => 'Send the closing odds as a string, like "1.90".',
        ];
    }

    /**
     * The bookmaker must be one of the user's active bookmakers, as on the form.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();

                if ($errors->hasAny(['bookmaker', 'bookmaker_id']) || $this->bookmaker() !== null) {
                    return;
                }

                $errors->add($this->has('bookmaker_id') ? 'bookmaker_id' : 'bookmaker', 'Choose one of your active bookmakers.');
            },
        ];
    }

    /**
     * The bet as sent, ready for RecordBet. Only call after validation.
     */
    public function betData(): BetData
    {
        $bookmaker = $this->bookmaker();

        abort_if($bookmaker === null, 422);

        /** @var list<array<string, mixed>> $selections */
        $selections = $this->validated('selections');

        return new BetData(
            bookmakerId: $bookmaker->id,
            placedAt: $this->filled('placed_at') ? self::parseDateTime($this->string('placed_at')->value()) ?? CarbonImmutable::now() : CarbonImmutable::now(),
            stake: Money::toMinorUnits($this->string('stake')->value()),
            exchangeRate: $this->filled('exchange_rate') ? $this->string('exchange_rate')->value() : $bookmaker->formattedExchangeRate(),
            isFreeBet: $this->boolean('is_free_bet'),
            tipster: $this->filled('tipster') ? $this->string('tipster')->value() : null,
            tags: $this->stringList($this->validated('tags')),
            notes: $this->filled('notes') ? $this->string('notes')->value() : null,
            selections: array_map(fn (array $selection): SelectionData => new SelectionData(
                sport: $this->stringOrNull($selection['sport'] ?? null) ?? '',
                competition: $this->stringOrNull($selection['competition'] ?? null),
                market: $this->stringOrNull($selection['market'] ?? null),
                teams: $this->stringList($selection['teams'] ?? null),
                eventName: $this->stringOrNull($selection['event_name'] ?? null) ?? '',
                eventStartsAt: is_string($selection['event_starts_at'] ?? null) ? self::parseDateTime($selection['event_starts_at']) : null,
                pick: $this->stringOrNull($selection['pick'] ?? null) ?? '',
                odds: $this->stringOrNull($selection['odds'] ?? null) ?? '',
                closingOdds: $this->stringOrNull($selection['closing_odds'] ?? null),
            ), $selections),
            probability: $this->filled('probability') ? $this->string('probability')->value() : null,
            betValue: $this->filled('bet_value') ? $this->string('bet_value')->value() : null,
        );
    }

    /**
     * The user who sent the request.
     */
    public function owner(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * The user's active bookmaker sent by id, or by name ignoring case, or null.
     */
    private function bookmaker(): ?Bookmaker
    {
        return once(function (): ?Bookmaker {
            $bookmakers = $this->owner()->bookmakers()->where('is_active', true);
            $id = $this->input('bookmaker_id');
            $name = $this->input('bookmaker');

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                return $bookmakers->find((int) $id);
            }

            if (! is_string($name)) {
                return null;
            }

            $candidates = $bookmakers->get();

            return $candidates->firstWhere('name', $name)
                ?? $candidates->first(fn (Bookmaker $bookmaker): bool => mb_strtolower($bookmaker->name) === mb_strtolower($name));
        });
    }

    /**
     * The product of the selection odds, or null until every selection has valid odds.
     */
    private function totalOdds(): ?string
    {
        $selections = $this->input('selections');

        if (! is_array($selections) || $selections === []) {
            return null;
        }

        $odds = [];

        foreach ($selections as $selection) {
            $value = is_array($selection) ? ($selection['odds'] ?? null) : null;

            if (! is_string($value) || ! Odds::isValidSelectionOdds($value)) {
                return null;
            }

            $odds[] = $value;
        }

        return Odds::total($odds);
    }

    /**
     * A valid ISO 8601 date-time as an instant in UTC, the time zone dates are stored
     * in. Without an offset the time is taken as UTC. Null for anything else, including
     * dates that don't exist ("2026-02-30").
     */
    private static function parseDateTime(string $value): ?CarbonImmutable
    {
        if (preg_match(self::DATE_TIME, $value, $parts) !== 1) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute] = $parts;
        $second = $parts[6] ?? '';

        if (! checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }

        $offset = $parts[7] ?? '';

        return CarbonImmutable::parse($value, $offset === '' ? 'UTC' : null)->utc();
    }

    /**
     * A decimal sent as a string (cleaned up as on the form) or a JSON integer; anything
     * else is left for validation to reject.
     */
    private function decimalInput(mixed $value): mixed
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) => Decimal::fromInput($value),
            default => $value,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
