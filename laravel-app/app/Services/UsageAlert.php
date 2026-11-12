<?php

namespace App\Services;

use App\Models\MeterReading;

/**
 * Unusual usage: this month is more than 50% above or below the tenant's average of the last 3 months.
 * Needs at least 2 earlier months, and skips very small averages (a jump from 1 to 2 m³ is not news).
 */
class UsageAlert
{
    public const CHANGE_PERCENT = 50;
    public const MIN_AVERAGE = 3;

    /**
     * @param  array  $usage  ['electric' => 84, 'water' => 5]
     * @return array  [['utility' => 'electric', 'usage' => 84, 'average' => 50.3, 'change_percent' => 67], …]
     */
    public static function check(int $tenantId, int $roomId, string $period, array $usage): array
    {
        $history = MeterReading::where('tenant_id', $tenantId)
            ->where('room_id', $roomId)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->limit(3)
            ->get();

        if ($history->count() < 2) {
            return [];
        }

        $alerts = [];
        foreach ($usage as $utility => $value) {
            $average = (float) $history->avg("{$utility}_usage");

            if ($value === null || $average < self::MIN_AVERAGE) {
                continue;
            }

            $change = (int) round(($value - $average) / $average * 100);

            if (abs($change) > self::CHANGE_PERCENT) {
                $alerts[] = ['utility' => $utility, 'usage' => (float) $value, 'average' => round($average, 1), 'change_percent' => $change];
            }
        }

        return $alerts;
    }
}
