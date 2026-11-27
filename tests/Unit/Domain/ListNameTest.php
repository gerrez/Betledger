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

    public function test_matching_names_contain_the_search_ignoring_case_best_match_first(): void
    {
        $names = ['Manchester United', 'Arsenal', 'Real Madrid', 'manchester city', 'Ørsted FC', 'Inter Milan', 'Lazio'];

        $this->assertSame(['manchester city', 'Manchester United'], ListName::matching($names, 'MAN', 20));
        $this->assertSame(['Real Madrid', 'Arsenal'], ListName::matching($names, 'r', 2));
        $this->assertSame(
            ['manchester city', 'Manchester United', 'Real Madrid', 'Roma'],
            ListName::matching(['Roma', 'Real Madrid', 'Manchester United', 'manchester city'], ' ma ', 20),
            'Starts with the search, then has a word starting with it, then contains it.',
        );
        $this->assertSame(['Ørsted FC'], ListName::matching($names, 'øR', 20));
        $this->assertSame([], ListName::matching($names, 'Chelsea', 20));
    }

    public function test_an_empty_search_lists_names_alphabetically_up_to_the_limit(): void
    {
        $this->assertSame(['Arsenal', 'chelsea', 'Everton'], ListName::matching(['Everton', 'Wolves', 'chelsea', 'Arsenal'], '', 3));
        $this->assertSame([], ListName::matching(['Arsenal'], '', 0));
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
