<?php

namespace Tests\Unit\Domain;

use App\Domain\ListName;
use PHPUnit\Framework\TestCase;

class ListNameTest extends TestCase
{
    public function test_names_may_not_contain_a_bar(): void
    {
        $this->assertTrue(ListName::isValid('Arsenal'));
        $this->assertTrue(ListName::isValid('Over/Under 2.5 · 1st half'));
        $this->assertTrue(ListName::isValid(''));

        $this->assertFalse(ListName::isValid('Live | Value'));
        $this->assertFalse(ListName::isValid('A|B'));
        $this->assertFalse(ListName::isValid('|'));
    }

    public function test_the_rule_fails_only_for_strings_with_a_bar(): void
    {
        $failures = [];
        $rule = ListName::rule();
        $fail = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        $rule('name', 'Arsenal', $fail);
        $rule('name', null, $fail);
        $rule('name', 'A | B', $fail);

        $this->assertSame([ListName::MESSAGE], $failures);
    }
}
