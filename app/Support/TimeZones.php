<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The time zones this server knows, for choosing one from a list.
 */
final class TimeZones
{
    public static function isKnown(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * Every time zone except UTC, grouped by region, each labelled by its
     * place: ['Europe' => ['Europe/London' => 'London', ...], ...].
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (timezone_identifiers_list() as $timezone) {
            if (! str_contains($timezone, '/')) {
                continue;
            }

            [$region, $place] = explode('/', $timezone, 2);
            $groups[$region][$timezone] = str_replace('_', ' ', Str::replace('/', ' / ', $place));
        }

        return $groups;
    }
}
