<?php

namespace App\Domain;

/**
 * A 24-hour time of day ("18:30") as typed into a form.
 */
final class TimeOfDay
{
    /**
     * The typed time as "HH:MM", or null when it isn't a valid 24-hour time. Hours and
     * minutes may be separated by a colon, a point or a comma (phone number pads often
     * have no colon), or not at all: "18:30", "18.30", "1830", "9:05" and "905" are all
     * accepted. Surrounding spaces are ignored.
     */
    public static function fromInput(string $input): ?string
    {
        if (preg_match('/^(\d{1,2})[:.,]?(\d{2})$/', trim($input), $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
