<?php

namespace Tests\Unit\Domain;

use App\Domain\Percent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PercentTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, int, string}>
     */
    public static function percentages(): array
    {
        return [
            'one decimal' => ['0.5238095238', false, 1, '52.4%'],
            'signed positive' => ['0.0569', true, 1, '+5.7%'],
            'negative' => ['-0.034', false, 1, '−3.4%'],
            'signed negative' => ['-1', true, 1, '−100.0%'],
            'half rounds up' => ['0.12345', false, 3, '12.345%'],
            'half up at one decimal' => ['0.00025', false, 2, '0.03%'],
            'negative half rounds away from zero' => ['-0.0005', true, 1, '−0.1%'],
            'whole percent' => ['0.6338', false, 0, '63%'],
            'zero never gets a sign' => ['0', true, 1, '0.0%'],
            'rounding to zero is unsigned' => ['-0.0004', true, 1, '0.0%'],
        ];
    }

    #[DataProvider('percentages')]
    public function test_formats_fractions_as_percentages(string $fraction, bool $signed, int $places, string $expected): void
    {
        $this->assertSame($expected, Percent::format($fraction, $signed, $places));
    }
}
