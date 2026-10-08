<?php

namespace Tests\Unit\Domain;

use App\Domain\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_multiplication_keeps_every_decimal_place(): void
    {
        $this->assertSame('0.000000000001', Decimal::normalize(Decimal::multiply('0.000001', '0.000001')));
    }

    public function test_rounds_half_up(): void
    {
        $this->assertSame('3', Decimal::round('2.5'));
        $this->assertSame('2', Decimal::round('2.4999999999'));
        $this->assertSame('1.0201', Decimal::round('1.020075', 4));
        $this->assertSame('1.0200', Decimal::round('1.02004999', 4));
    }

    public function test_rounds_negative_values_half_away_from_zero(): void
    {
        $this->assertSame('-3', Decimal::round('-2.5'));
        $this->assertSame('-2', Decimal::round('-2.4'));
        $this->assertSame('0', Decimal::round('-0.4'));
    }

    public function test_normalize_drops_trailing_zeros(): void
    {
        $this->assertSame('2.7', Decimal::normalize('2.7000'));
        $this->assertSame('3', Decimal::normalize('3.000'));
        $this->assertSame('100', Decimal::normalize('100'));
        $this->assertSame('0', Decimal::normalize('-0.000'));
    }

    public function test_to_integer_rejects_values_outside_the_integer_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::toInteger('9223372036854775808');
    }

    public function test_rejects_values_that_are_not_plain_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::multiply('1e3', '2');
    }

    public function test_division_by_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::divide('1', '0.000');
    }
}
