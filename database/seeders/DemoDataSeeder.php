<?php

namespace Database\Seeders;

use App\Domain\Bets\SettleBet;
use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Odds;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Tag;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo accounts for trying the app locally: `php artisan db:seed`.
 *
 * Every run starts the demo accounts over (their old data is deleted), so changes made
 * while testing are easy to undo. Never runs in production: the passwords are public.
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@example.com';

    public const OTHER_EMAIL = 'other@example.com';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Skipped: demo accounts are never created in production.');

            return;
        }

        DB::transaction(function (): void {
            User::query()->whereIn('email', [self::DEMO_EMAIL, self::OTHER_EMAIL])->delete();

            $this->seedDemoUser($this->createUser('Demo', self::DEMO_EMAIL));
            $this->seedOtherUser($this->createUser('Other User', self::OTHER_EMAIL));
        });

        $this->command->info('Log in as '.self::DEMO_EMAIL.' (or '.self::OTHER_EMAIL.') with password "'.self::PASSWORD.'".');
    }

    private function createUser(string $name, string $email): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'base_currency' => Currency::Eur,
        ])->save();

        return $user;
    }

    /**
     * The account to test with: bookmakers in several currencies (one inactive), every
     * list, and bets that are open and settled, singles and accumulators, cash and free,
     * enough for more than one page of the bets list.
     * Some entries are unused (Ice hockey, Both teams to score, Value hunting, boost) so
     * they can be deleted on the Lists page.
     */
    private function seedDemoUser(User $user): void
    {
        $unibet = $user->bookmakers()->create(['name' => 'Unibet', 'currency' => Currency::Eur, 'exchange_rate' => '1']);
        $bet365 = $user->bookmakers()->create(['name' => 'Bet365', 'currency' => Currency::Gbp, 'exchange_rate' => '1.17']);
        $danskeSpil = $user->bookmakers()->create(['name' => 'Danske Spil', 'currency' => Currency::Dkk, 'exchange_rate' => '0.1341']);
        $oldBookie = $user->bookmakers()->create(['name' => 'Old Bookie', 'currency' => Currency::Eur, 'exchange_rate' => '1', 'is_active' => false]);
        // No bets: Settings → Import fills it from tests/Fixtures/sportmarket/orders.csv.
        $user->bookmakers()->create(['name' => 'Sportmarket', 'currency' => Currency::Eur, 'exchange_rate' => '1']);

        $football = $user->sports()->create(['name' => 'Football']);
        $tennis = $user->sports()->create(['name' => 'Tennis']);
        $basketball = $user->sports()->create(['name' => 'Basketball']);
        $user->sports()->create(['name' => 'Ice hockey']);

        $competitions = [];
        foreach (['Premier League', 'La Liga', 'Champions League'] as $name) {
            $competitions[$name] = $user->competitions()->create(['sport_id' => $football->id, 'name' => $name]);
        }
        foreach (['Wimbledon', 'US Open'] as $name) {
            $competitions[$name] = $user->competitions()->create(['sport_id' => $tennis->id, 'name' => $name]);
        }
        $competitions['NBA'] = $user->competitions()->create(['sport_id' => $basketball->id, 'name' => 'NBA']);

        $teams = [];
        foreach (['Arsenal', 'Chelsea', 'Liverpool', 'Real Madrid', 'FC Barcelona'] as $name) {
            $teams[$name] = $user->teams()->create(['sport_id' => $football->id, 'name' => $name]);
        }
        foreach (['Carlos Alcaraz', 'Jannik Sinner'] as $name) {
            $teams[$name] = $user->teams()->create(['sport_id' => $tennis->id, 'name' => $name]);
        }
        foreach (['Boston Celtics', 'Los Angeles Lakers'] as $name) {
            $teams[$name] = $user->teams()->create(['sport_id' => $basketball->id, 'name' => $name]);
        }

        $markets = [];
        foreach (['1X2', 'Asian handicap', 'Over/Under', 'Both teams to score'] as $name) {
            $markets[$name] = $user->markets()->create(['name' => $name]);
        }
        $ownModel = $user->tipsters()->create(['name' => 'Own model']);
        $user->tipsters()->create(['name' => 'Value hunting']);
        $tags = [];
        foreach (['value', 'live', 'boost'] as $name) {
            $tags[$name] = $user->tags()->create(['name' => $name]);
        }

        $leg = fn (string $home, string $away, string $competition, string $market, string $pick, string $odds, ?SelectionResult $result, int $daysAgo, ?string $closingOdds = null): array => [
            'sport_id' => $teams[$home]->sport_id,
            'competition_id' => $competitions[$competition]->id,
            'market_id' => $markets[$market]->id,
            'event_name' => "{$home} v {$away}",
            'event_starts_at' => now()->subDays($daysAgo)->setTime(18, 0),
            'pick' => $pick,
            'odds' => $odds,
            'closing_odds' => $closingOdds,
            'result' => $result,
            'teams' => [$teams[$home], $teams[$away]],
        ];

        $this->placeBet($unibet, 2000, daysAgo: 12, tipster: $ownModel, tags: [$tags['value']], notes: 'Saka back in training; line moved from 2.20.', selections: [
            $leg('Arsenal', 'Chelsea', 'Premier League', '1X2', 'Arsenal', '2.10', SelectionResult::Won, 11, closingOdds: '1.95'),
        ]);
        $this->placeBet($bet365, 1500, daysAgo: 9, selections: [
            $leg('Real Madrid', 'FC Barcelona', 'La Liga', 'Asian handicap', 'Real Madrid −0.25', '1.95', SelectionResult::HalfLost, 8, closingOdds: '2.02'),
        ]);
        $this->placeBet($unibet, 1000, daysAgo: 7, selections: [
            $leg('Liverpool', 'Arsenal', 'Champions League', '1X2', 'Liverpool', '1.50', SelectionResult::Won, 6),
            $leg('Chelsea', 'FC Barcelona', 'Champions League', '1X2', 'Chelsea', '2.00', SelectionResult::Void, 6),
            $leg('Real Madrid', 'Liverpool', 'Champions League', 'Over/Under', 'Over 2.5', '1.80', SelectionResult::Won, 6),
        ]);
        $this->placeBet($bet365, 1000, daysAgo: 5, isFreeBet: true, tags: [$tags['live']], selections: [
            $leg('Boston Celtics', 'Los Angeles Lakers', 'NBA', '1X2', 'Boston Celtics', '3.00', SelectionResult::Won, 5),
        ]);
        $this->placeBet($unibet, 2500, daysAgo: 4, tipster: $ownModel, selections: [
            $leg('Carlos Alcaraz', 'Jannik Sinner', 'Wimbledon', '1X2', 'Carlos Alcaraz', '1.75', SelectionResult::Lost, 3, closingOdds: '1.70'),
        ]);
        // Calculated payout 102.50; the bookmaker paid 100.00, so it was entered by hand.
        $cappedWin = $this->placeBet($oldBookie, 5000, daysAgo: 40, selections: [
            $leg('Chelsea', 'Liverpool', 'Premier League', 'Over/Under', 'Under 2.5', '2.05', SelectionResult::Won, 39),
        ]);
        app(SettleBet::class)->overridePayout($cappedWin, 10000);
        $this->placeBet($danskeSpil, 10000, daysAgo: 1, selections: [
            $leg('Liverpool', 'Chelsea', 'Premier League', '1X2', 'Liverpool', '1.65', SelectionResult::Won, 1),
            $leg('Jannik Sinner', 'Carlos Alcaraz', 'US Open', '1X2', 'Jannik Sinner', '2.20', null, -2),
        ]);
        $this->placeBet($unibet, 2000, daysAgo: 0, tags: [$tags['value']], selections: [
            $leg('Arsenal', 'Liverpool', 'Premier League', 'Over/Under', 'Over 2.5', '1.90', null, -3),
        ]);

        // Older settled singles, so the bets list has more than one page.
        $history = [
            ['Arsenal', 'Chelsea', 'Premier League', 'Arsenal', '2.25', SelectionResult::Lost],
            ['Liverpool', 'Real Madrid', 'Champions League', 'Liverpool', '2.60', SelectionResult::Won],
            ['Carlos Alcaraz', 'Jannik Sinner', 'US Open', 'Jannik Sinner', '1.85', SelectionResult::Won],
            ['Boston Celtics', 'Los Angeles Lakers', 'NBA', 'Los Angeles Lakers', '2.35', SelectionResult::Lost],
            ['FC Barcelona', 'Real Madrid', 'La Liga', 'FC Barcelona', '2.05', SelectionResult::Void],
            ['Chelsea', 'Arsenal', 'Premier League', 'Chelsea', '3.10', SelectionResult::Lost],
            ['Jannik Sinner', 'Carlos Alcaraz', 'Wimbledon', 'Carlos Alcaraz', '1.95', SelectionResult::Won],
            ['Liverpool', 'Chelsea', 'Premier League', 'Liverpool', '1.70', SelectionResult::Won],
            ['Los Angeles Lakers', 'Boston Celtics', 'NBA', 'Boston Celtics', '1.60', SelectionResult::Lost],
            ['Real Madrid', 'FC Barcelona', 'La Liga', 'Real Madrid', '2.15', SelectionResult::Won],
            ['Arsenal', 'Liverpool', 'Premier League', 'Liverpool', '2.80', SelectionResult::Lost],
            ['FC Barcelona', 'Chelsea', 'Champions League', 'FC Barcelona', '1.90', SelectionResult::Won],
            ['Carlos Alcaraz', 'Jannik Sinner', 'Wimbledon', 'Carlos Alcaraz', '1.75', SelectionResult::Lost],
            ['Chelsea', 'Liverpool', 'Premier League', 'Chelsea', '2.45', SelectionResult::Won],
            ['Boston Celtics', 'Los Angeles Lakers', 'NBA', 'Boston Celtics', '1.80', SelectionResult::Won],
            ['Real Madrid', 'Liverpool', 'Champions League', 'Real Madrid', '2.00', SelectionResult::Lost],
        ];
        foreach ($history as $index => [$home, $away, $competition, $pick, $odds, $result]) {
            $daysAgo = 45 + $index * 6;
            $bookmaker = $index % 3 === 0 ? $bet365 : $unibet;

            $this->placeBet($bookmaker, 1000 + 500 * ($index % 4), daysAgo: $daysAgo, selections: [
                $leg($home, $away, $competition, '1X2', $pick, $odds, $result, $daysAgo - 1),
            ]);
        }
    }

    /**
     * A second account whose data must never show up for the demo user.
     */
    private function seedOtherUser(User $user): void
    {
        $bookmaker = $user->bookmakers()->create(['name' => 'Other Users Bookie', 'currency' => Currency::Eur, 'exchange_rate' => '1']);

        $sport = $user->sports()->create(['name' => 'Other Users Sport']);
        $competition = $user->competitions()->create(['sport_id' => $sport->id, 'name' => 'Other Users Competition']);
        $team = $user->teams()->create(['sport_id' => $sport->id, 'name' => 'Other Users Team']);
        $market = $user->markets()->create(['name' => 'Other Users Market']);
        $tipster = $user->tipsters()->create(['name' => 'Other Users Tipster']);
        $tag = $user->tags()->create(['name' => 'Other Users Tag']);

        $this->placeBet($bookmaker, 5000, daysAgo: 2, tipster: $tipster, tags: [$tag], selections: [[
            'sport_id' => $sport->id,
            'competition_id' => $competition->id,
            'market_id' => $market->id,
            'event_name' => 'Other Users Event',
            'event_starts_at' => now()->subDay(),
            'pick' => 'Other Users Pick',
            'odds' => '2.50',
            'result' => SelectionResult::Won,
            'teams' => [$team],
        ]]);
    }

    /**
     * Record a bet with its selections in the bookmaker's currency and rate, and settle
     * it when its results say so.
     *
     * @param  int  $stake  minor units
     * @param  list<Tag>  $tags
     * @param  list<array<string, mixed>>  $selections  selection attributes plus `teams` (list<Team>)
     */
    private function placeBet(Bookmaker $bookmaker, int $stake, int $daysAgo, array $selections, bool $isFreeBet = false, ?Tipster $tipster = null, array $tags = [], ?string $notes = null): Bet
    {
        $bet = new Bet([
            'bookmaker_id' => $bookmaker->id,
            'tipster_id' => $tipster?->id,
            'type' => BetType::forSelectionCount(count($selections)),
            'placed_at' => now()->subDays($daysAgo)->setTime(12, 0),
            'stake' => $stake,
            'currency' => $bookmaker->currency,
            'exchange_rate' => $bookmaker->exchange_rate,
            'is_free_bet' => $isFreeBet,
            'total_odds' => Odds::total(array_column($selections, 'odds')),
            'notes' => $notes,
        ]);
        $bet->user()->associate($bookmaker->user_id);
        $bet->save();
        $bet->tags()->attach($tags);

        $legs = [];
        foreach ($selections as $position => $attributes) {
            $teams = $attributes['teams'];
            unset($attributes['teams']);

            $selection = $bet->selections()->create(['position' => $position + 1, ...$attributes]);
            $selection->teams()->attach($teams);
            $legs[] = new Leg($selection->odds, $selection->result);
        }

        $settlement = (new SettlementCalculator)->settle($stake, $isFreeBet, $legs);

        if ($settlement !== null) {
            $bet->forceFill([
                'status' => BetStatus::Settled,
                'settled_at' => $bet->selections->max('event_starts_at')?->copy()->addHours(2),
                'payout' => $settlement->payout,
                'profit' => $settlement->profit,
            ])->save();
        }

        return $bet;
    }
}
