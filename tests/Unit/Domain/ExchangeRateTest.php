<?php

namespace Tests\Unit\Domain;

use App\Domain\ExchangeRate;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ExchangeRateTest extends TestCase
{
    #[TestWith(['1'])]
    #[TestWith(['7.46'])]
    #[TestWith(['0.1341'])]
    #[TestWith(['0.00000001'], 'smallest rate the column holds')]
    #[TestWith(['9999999999.99999999'], 'largest rate the column holds')]
    public function test_accepts_positive_decimals_that_fit_the_column(string $rate): void
    {
        $this->assertTrue(ExchangeRate::isValid($rate));
    }

    #[TestWith(['0'], 'zero')]
    #[TestWith(['0.00000000'], 'zero with decimals')]
    #[TestWith(['-1.5'], 'negative')]
    #[TestWith(['0.123456789'], 'more than 8 decimals')]
    #[TestWith(['12345678901'], 'more than 10 whole digits')]
    #[TestWith(['7,46'], 'decimal comma')]
    #[TestWith(['.5'], 'no leading digit')]
    #[TestWith(['1e3'], 'exponent')]
    #[TestWith([''], 'empty')]
    #[TestWith(['abc'], 'not a number')]
    public function test_rejects_anything_else(string $rate): void
    {
        $this->assertFalse(ExchangeRate::isValid($rate));
    }
}
