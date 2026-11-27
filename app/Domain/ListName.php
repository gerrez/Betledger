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
     * The names that contain the search, ignoring case, best match first: names that
     * start with it, then names with a word that starts with it, then the rest; each
     * group alphabetical. An empty search matches every name.
     *
     * @param  array<string>  $names
     * @return list<string>
     */
    public static function matching(array $names, string $search, int $limit): array
    {
        $search = mb_strtolower(trim($search));
        $ranked = [];

        foreach ($names as $name) {
            $lowerName = mb_strtolower($name);
            $position = $search === '' ? 0 : mb_strpos($lowerName, $search);

            if ($position === false) {
                continue;
            }

            $rank = match (true) {
                $position === 0 => 0,
                preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($search, '/').'/u', $lowerName) === 1 => 1,
                default => 2,
            };

            $ranked[] = ['name' => $name, 'rank' => $rank, 'key' => $lowerName];
        }

        usort($ranked, fn (array $a, array $b): int => [$a['rank'], $a['key'], $a['name']] <=> [$b['rank'], $b['key'], $b['name']]);

        return array_column(array_slice($ranked, 0, max(0, $limit)), 'name');
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
