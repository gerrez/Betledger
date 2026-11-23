<?php

namespace App\Domain;

use Closure;

/**
 * The names of list entries (sports, competitions, teams, markets, tipsters, tags). They
 * may not contain "|", which the CSV export uses to join a bet's tags and a selection's
 * teams (see "Export" in docs/spec.md), so a re-import can split them again.
 */
final class ListName
{
    public const FORBIDDEN = '|';

    public const MESSAGE = 'Names can’t contain “|”.';

    public static function isValid(string $name): bool
    {
        return ! str_contains($name, self::FORBIDDEN);
    }

    /**
     * A validation rule refusing names that contain FORBIDDEN.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(string $message = self::MESSAGE): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (is_string($value) && ! self::isValid($value)) {
                $fail($message);
            }
        };
    }
}
