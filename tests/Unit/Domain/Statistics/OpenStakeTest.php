<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\Statistics\OpenStake;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OpenStakeTest extends TestCase
{
    public function test_every_bets_stake_is_converted_and_rounded_on_its_own(): void
    {
        // 3 × (0.01 × 1.5 = 0.015 → 0.02), not 0.03 × 1.5 = 0.045 → 0.05.
        $open = OpenStake::fromGroups([
            ['stake' => 1, 'exchangeRate' => '1.50000000', 'isFreeBet' => false, 'bets' => 3],
            ['stake' => 2500, 'exchangeRate' => '1', 'isFreeBet' => false, 'bets' => 2],
        ]);

        $this->assertSame(5, $open->bets);
        $this->assertSame(3 * 2 + 2 * 2500, $open->stake);
    }

    public function test_free_bets_count_as_open_bets_without_stake(): void
    {
        $open = OpenStake::fromGroups([
            ['stake' => 1000, 'exchangeRate' => '1', 'isFreeBet' => true, 'bets' => 2],
            ['stake' => 1000, 'exchangeRate' => '1.17', 'isFreeBet' => false, 'bets' => 1],
        ]);

        $this->assertSame(3, $open->bets);
        $this->assertSame(1170, $open->stake);
    }

    public function test_no_open_bets_is_nothing_at_stake(): void
    {
        $open = OpenStake::fromGroups([]);

        $this->assertSame([0, 0], [$open->bets, $open->stake]);
    }

    public function test_a_group_has_at_least_one_bet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenStake::fromGroups([['stake' => 1000, 'exchangeRate' => '1', 'isFreeBet' => false, 'bets' => 0]]);
    }
}
