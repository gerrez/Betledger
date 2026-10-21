<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\Statistics\OddsBand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OddsBandTest extends TestCase
{
    /**
     * @return array<string, array{string, OddsBand}>
     */
    public static function odds(): array
    {
        return [
            'lowest odds' => ['1.0001', OddsBand::UpTo150],
            'top of the first band' => ['1.5000', OddsBand::UpTo150],
            'just above 1.50' => ['1.5001', OddsBand::UpTo200],
            'between two-decimal bounds' => ['1.5050', OddsBand::UpTo200],
            'evens' => ['2.0000', OddsBand::UpTo200],
            'three' => ['3.0000', OddsBand::UpTo300],
            'five' => ['5.0000', OddsBand::UpTo500],
            'ten' => ['10.0000', OddsBand::UpTo1000],
            'just above ten' => ['10.0001', OddsBand::Over1000],
            'long shot' => ['250.0000', OddsBand::Over1000],
        ];
    }

    #[DataProvider('odds')]
    public function test_a_band_runs_up_to_and_including_its_upper_bound(string $totalOdds, OddsBand $band): void
    {
        $this->assertSame($band, OddsBand::forOdds($totalOdds));
    }

    public function test_odds_must_be_valid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OddsBand::forOdds('1.0000');
    }

    public function test_labels_use_an_en_dash(): void
    {
        $this->assertSame(
            ["1.01\u{2013}1.50", "1.51\u{2013}2.00", "2.01\u{2013}3.00", "3.01\u{2013}5.00", "5.01\u{2013}10.00", '10.01+'],
            array_map(fn (OddsBand $band): string => $band->label(), OddsBand::cases()),
        );
    }
}
