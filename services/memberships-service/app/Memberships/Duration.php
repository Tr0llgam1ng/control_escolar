<?php

namespace App\Memberships;

/** Seconds → days / hours / minutes (what the time modules show). */
final class Duration
{
    public static function parts(int $seconds): array
    {
        $seconds = max(0, $seconds);

        return [
            'seconds' => $seconds,
            'days' => intdiv($seconds, 86400),
            'hours' => intdiv($seconds % 86400, 3600),
            'minutes' => intdiv($seconds % 3600, 60),
            'human' => self::human($seconds),
        ];
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);

        $out = array_filter([$d ? "{$d} d" : null, $h ? "{$h} h" : null, $m ? "{$m} min" : null]);

        return $out ? implode(' ', $out) : ($seconds > 0 ? 'menos de 1 min' : '0 min');
    }
}
