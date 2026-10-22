<?php

namespace Tests\Unit\Domain;

use App\Domain\TimeOfDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeOfDayTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function inputs(): array
    {
        return [
            'colon' => ['18:30', '18:30'],
            'point' => ['18.30', '18:30'],
            'comma' => ['18,30', '18:30'],
            'no separator' => ['1830', '18:30'],
            'one-digit hour' => ['9:05', '09:05'],
            'one-digit hour without separator' => ['905', '09:05'],
            'surrounding spaces' => [' 07:45 ', '07:45'],
            'midnight' => ['0:00', '00:00'],
            'last minute' => ['23:59', '23:59'],
            'empty' => ['', null],
            'hour 24' => ['24:00', null],
            'minute 60' => ['12:60', null],
            'one-digit minutes' => ['18:3', null],
            'hour only' => ['18', null],
            'three-digit hour' => ['118:30', null],
            'am/pm' => ['6:30 PM', null],
            'seconds' => ['18:30:00', null],
            'two separators' => ['18::30', null],
            'negative' => ['-1:30', null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_from_input(string $input, ?string $expected): void
    {
        $this->assertSame($expected, TimeOfDay::fromInput($input));
    }
}
