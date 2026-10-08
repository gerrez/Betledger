<?php

namespace Tests\Unit\Domain\Settlement;

use App\Domain\Decimal;
use App\Domain\Settlement\SelectionResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SelectionResultTest extends TestCase
{
    /**
     * @return array<string, array{SelectionResult, string}>
     */
    public static function factorsAtOdds195(): array
    {
        return [
            'won' => [SelectionResult::Won, '1.95'],
            'lost' => [SelectionResult::Lost, '0'],
            'void' => [SelectionResult::Void, '1'],
            'half won' => [SelectionResult::HalfWon, '1.475'],
            'half lost' => [SelectionResult::HalfLost, '0.5'],
        ];
    }

    #[DataProvider('factorsAtOdds195')]
    public function test_returns_the_factor_for_each_result(SelectionResult $result, string $expected): void
    {
        $factor = $result->factor('1.95');

        $this->assertSame($expected, Decimal::normalize($factor));
    }

    public function test_rejects_odds_of_one_or_less(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SelectionResult::Won->factor('1.00');
    }
}
