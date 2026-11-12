<?php

namespace App\Services;

use App\Models\Room;

class UtilityTariff
{
    public const MODES = ['whole', 'progressive'];

    /**
     * Charge for one utility of one room.
     *
     * 1. The room has its own rate (method C) → use it
     * 2. Otherwise → tiered rates with method A (whole) or B (progressive) from Settings
     * 3. The minimum charge from Settings applies, except to fixed monthly charges
     *
     * @param  string  $utility  "electric" or "water"
     * @return array{amount: int, rate: int, method: string, minimum_applied: bool}
     */
    public static function charge(float $usage, string $utility, array $settings, Room $room): array
    {
        $type = $room->{"{$utility}_rate_type"};
        $rate = $room->{"{$utility}_custom_rate"};

        if ($type !== null && $rate !== null) {
            $result = self::custom($usage, $type, (int) $rate);
            $result['method'] = $type; // per_unit or fixed
        } else {
            $mode = $settings["{$utility}_tier_mode"] ?? 'whole';
            $result = self::calculate($usage, $settings["{$utility}_tiers"], $mode);
            $result['method'] = $mode; // whole or progressive
        }

        $minimum = (int) ($settings["{$utility}_min_charge"] ?? 0);
        $result['minimum_applied'] = false;

        if ($result['method'] !== 'fixed' && $result['amount'] < $minimum) {
            $result['amount'] = $minimum;
            $result['minimum_applied'] = true;
        }

        return $result;
    }

    /**
     * Method C: the room's own rate.
     * per_unit: usage × rate (e.g. 15 m³ × 2,000 = 30,000)
     * fixed:    the same amount every month, even when nothing was used (e.g. 10,000)
     */
    public static function custom(float $usage, string $type, int $rate): array
    {
        if ($type === 'fixed') {
            return [
                'amount' => $rate,
                'rate'   => $usage > 0 ? (int) round($rate / $usage) : 0,
            ];
        }

        return [
            'amount' => self::roundRiel($usage * $rate),
            'rate'   => $rate,
        ];
    }

    /**
     * Methods A and B with tiered rates.
     *
     * whole:       the whole usage is charged at the rate of the tier it falls into
     *              e.g. 60 kWh → 60 × 610 = 36,600
     * progressive: each part of the usage is charged at its own tier's rate
     *              e.g. 20 m³ → 7 × 400 + 8 × 720 + 5 × 960 = 13,360 → 13,400
     *
     * @param  array  $tiers  [['up_to' => 10, 'rate' => 380], ..., ['up_to' => null, 'rate' => 730]]
     * @return array{amount: int, rate: int}  amount in riel (rounded to 100), rate = average riel per unit
     */
    public static function calculate(float $usage, array $tiers, string $mode = 'whole'): array
    {
        $tiers = self::sorted($tiers);

        if ($usage <= 0) {
            return ['amount' => 0, 'rate' => (int) $tiers[0]['rate']];
        }

        if ($mode === 'progressive') {
            $amount = 0;
            $lower = 0;

            foreach ($tiers as $tier) {
                $upper = $tier['up_to'];
                $units = ($upper === null ? $usage : min($usage, $upper)) - $lower;

                if ($units > 0) {
                    $amount += $units * $tier['rate'];
                }

                if ($upper === null || $usage <= $upper) {
                    break;
                }

                $lower = $upper;
            }

            return [
                'amount' => self::roundRiel($amount),
                'rate'   => (int) round($amount / $usage),
            ];
        }

        // whole
        foreach ($tiers as $tier) {
            if ($tier['up_to'] === null || $usage <= $tier['up_to']) {
                return [
                    'amount' => self::roundRiel($usage * $tier['rate']),
                    'rate'   => (int) $tier['rate'],
                ];
            }
        }

        $last = end($tiers);

        return ['amount' => self::roundRiel($usage * $last['rate']), 'rate' => (int) $last['rate']];
    }

    // Lowest tier first, the tier without a limit last
    public static function sorted(array $tiers): array
    {
        usort($tiers, function ($a, $b) {
            if ($a['up_to'] === null) return 1;
            if ($b['up_to'] === null) return -1;
            return $a['up_to'] <=> $b['up_to'];
        });

        return array_values($tiers);
    }

    // Round to the nearest 100 riel, e.g. 12,340 → 12,300
    public static function roundRiel(float $amount): int
    {
        return (int) (round($amount / 100) * 100);
    }
}